<?php
/*
 * This file is part of the Volta package.
 *
 * (c) Rob Demmenie <rob@volta-framework.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Volta\Component\Books;

use FilesystemIterator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Volta\Component\Books\Exceptions\Exception;


/**
 * @see https://ebookflightdeck.com/
 * @see https://en.wikipedia.org/wiki/EPUB
 */
class Epub implements LoggerAwareInterface
{

    use LoggerAwareTrait;


    private BookNode $_book;
    private string $_template;
    private string $_style;
    private string $_bookId;
    private string $_contentDir = 'OEBPS';
    private string $_metadataFileName = 'metadata.opf';
    private string $_tocFileName = 'toc.ncx';
    private string $_sourceDirName = 'src';

    public function  __construct(BookNode $book, string $template, string $style)
    {
        $this->_book = $book;
        $this->_template = $template;
        $this->_style = $style;
        $this->_bookId = sha1(uniqid('VOLTA', true));
    }

    /**
     * Exports a Volta Book to an epub file
     *
     * @see https://en.wikipedia.org/wiki/EPUB
     * @param string $destination Existing empty writable directory
     * @return bool True on success, false otherwise
     * @throws Exception When an invalid destination is given
     */
    public function export(string $destination): bool
    {

        $this->getLogger()->notice('#1 SETUP');
        $this->_setup();

        $this->getLogger()->notice('#2 DESTINATION');
        $this->_setDestination($destination);

        $this->getLogger()->notice('#3 OPEN CONTAINER');
        $this->_createOpenContainer();

        $this->getLogger()->notice('#4 CONTENT');
        $this->_createEpubContent();

        $this->getLogger()->notice('#5 TOC');
        $this->_createEpubToc();

        $this->getLogger()->notice('#6 ADD RESOURCES');
        $this->_addResources();

        $this->getLogger()->notice('#6 ZIP IT');
        $this->_zipIt();

        return true;
    }

    #region - #1 Setup and Teardown

    private int $_oldPublishingMode = 0;
    /**
     * Setup environment for creating the EPUB
     *
     * @return void
     */
    private function _setup():void
    {
        $this->_oldPublishingMode = Settings::getPublishingMode();
        Settings::setPublishingMode(Settings::PUBLISHING_EPUB);

        $this->getLogger()->info('Set temporarily error handler', [__METHOD__]);
        $errorHandler = function(int $code, string $message, null|string $file = null, null|int $line = null, null|array $context = null ): bool {
            $this->getLogger()->error("$message in $file @ $line");
            $this->_teardown();
            exit(1);
        };
        $errorHandler->bindTo($this);
        set_error_handler($errorHandler);

        $this->getLogger()->info('Set temporarily exception handler', [__METHOD__]);
        $exceptionHandler = function(\Throwable $exception): void {
            $this->getLogger()->error(get_class($exception) . ' - ' . $exception->getMessage());
            $this->_teardown();
            exit();
        };
        $exceptionHandler->bindTo($this);
        set_exception_handler($exceptionHandler);
    }

    /**
     * Restore environment
     *
     * @return void
     */
    private function _teardown():void
    {
        $this->getLogger()->info('Restore error and exception handler', [__METHOD__]);

        Settings::setPublishingMode($this->_oldPublishingMode);
        restore_error_handler();
        restore_exception_handler();
    }

    #endregion
    #region - #2 Validate and Sanitize  Destination

    private string $_destination;

    /**
     *
     * @return string
     */
    public function getSourceDir(): string
    {
        return $this->_destination . $this->_sourceDirName . DIRECTORY_SEPARATOR;
    }

    public function getDestination(): string
    {
        return $this->_destination;
    }

    /**
     * @param string $destination
     * @return self
     * @throws Exception
     */
    private function _setDestination(string $destination): self
    {
        // Validate the destination directory
        if (!is_dir($destination) || !is_writable($destination)) {
            throw new Exception('Destination not pointing to an existing writable directory ' . count(scandir($destination)) );
        }

        // Sanitize the destination directory
        $destination = realpath($destination) . DIRECTORY_SEPARATOR;
        $it = new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        if (count(scandir($destination)) > 2) {
            throw new Exception('Destination not pointing to an empty directory');
        }
        $this->getLogger()->debug('Destination made empty');
        $this->_destination = $destination;

        // add the src dir
        mkdir($this->_destination . $this->_sourceDirName);
        $this->getLogger()->info('Destination set to ' . $this->_destination);

        return $this;
    }

    #endregion
    #region - #3 Create Basic Open Container Organisation

    /**
     * @return void
     * @throws Exception
     */
    private function _createOpenContainer(): void
    {
        // creating the required mimetype file
        $fh = fopen($this->getSourceDir(). 'mimetype', 'w');
        fwrite($fh, 'application/epub+zip'); // application/epub+zip
        fclose($fh);
        $this->getLogger()->info('Created ' . 'mimetype');

        // creating the required container file
        if (false === mkdir($this->getSourceDir() . 'META-INF')) {
            throw new Exception('Failed to create required directory META-INF');
        }
        $fh = fopen($this->getSourceDir(). 'META-INF'. DIRECTORY_SEPARATOR . 'container.xml', 'w');
        fwrite($fh, '<?xml version="1.0" encoding="UTF-8" ?>' . PHP_EOL);
        fwrite($fh, '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">' . PHP_EOL);
        fwrite($fh, '  <rootfiles>' . PHP_EOL);
        fwrite($fh, '    <rootfile full-path="' . $this->_metadataFileName. '" media-type="application/oebps-package+xml"/>' . PHP_EOL);
        fwrite($fh, '  </rootfiles>' . PHP_EOL);
        fwrite($fh, '</container>' . PHP_EOL);
        fclose($fh);
        $this->getLogger()->info('Created ' . 'META-INF'. DIRECTORY_SEPARATOR . 'container.xml');

        // creating the required root file(s)
        if (false === mkdir($this->getSourceDir() . 'OEBPS')) {
            throw new Exception('Failed to create required directory OEBPS');
        }
    }
    #endregion
    #region - #4 Create EPUB Content

    /**
     * @return void
     * @throws Exception
     */
    private function _createEpubContent(): void
    {
        // build the opf data
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id">';
        $xml[] = '  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">';
        $xml[] = '    <dc:identifier id="pub-id" opf:scheme="uuid">' .$this->_bookId . '</dc:identifier>';
        $xml[] = '    <meta refines="#pub-id" property="identifier-type" scheme="xsd:string">uuid</meta>';
        $xml[] = '    <meta property="dcterms:modified">2014-04-04T14:09:43Z</meta>';
        $xml[] = '    <dc:title id="title1">' . $this->_book->getName() . '</dc:title>';
        $xml[] = '    <meta refines="#title1" property="title-type">main</meta>';
        $xml[] = '    <meta refines="#title1" property="display-seq">1</meta>';
        $xml[] = '    <dc:language>e' . $this->_book->getMeta()->get('language', 'en-US'). '</dc:language>';
        $xml[] = '    <dc:creator opf:file-as="' .$this->_book->getMeta()->get('author', 'anonymous'). '" opf:role="aut">' .$this->_book->getMeta()->get('author', 'anonymous'). '</dc:creator>';
        $xml[] = '  </metadata>';
        $xml[] = '  <manifest>';

        // - build manifest
        $manifest = [];
        $this->_createManifest($this->_book,  $manifest);
        foreach($manifest as $item) {
            $xml[] = '    <item id="'.$item['id'].'" href="'.$item['href'].'" media-type="'.$item['media-type'].'"/>';
        }


        // add special entries
        $xml[] = '    <item id="cover" properties="cover-image" href="cover.png" media-type="image/png" />';
        $xml[] = '    <item id="ncx" href="' . $this->_tocFileName . '" media-type="application/x-dtbncx+xml"/>';
        $xml[] = '  </manifest>';
        $xml[] = '  <spine toc="ncx">';
        foreach($manifest as $item) {
            $xml[] = '    <itemref idref="'.$item['id'].'"/>';
        }
        $xml[] = '  </spine>';
        $xml[] = '</package>';

        // write the file
        $fh = fopen( $this->getSourceDir() . $this->_metadataFileName, 'w');
        fwrite($fh, trim(implode(PHP_EOL, $xml)));
        fclose($fh);
        $this->getLogger()->info('Created ' . $this->_metadataFileName, [__METHOD__]);

    }



    /**
     * Create all the content files and stores the in the manifest array
     * @param NodeInterface $node
     * @param array $manifest
     * @return void
     */
    private function _createManifest(NodeInterface $node, array &$manifest): void
    {
        foreach($this->_book->getList() as $node) {
            $file = $this->_getFileName($node);
            $manifest[$node->getUri()] = [
                'id' => $this->_getResourceId($node),
                'href' => $file,
                'media-type' => 'application/xhtml+xml'
            ];
            $fh = fopen($this->getSourceDir() . $file, 'w');
            $level = count(explode('/', $node->getUri())) -1;

            ob_start();
            include $this->_template;
            $content = ob_get_contents();
            ob_end_clean();

            if (false !== fwrite($fh, $content)) {
                $this->getLogger()->info('Created ' . $file);
            } else {
                $this->getLogger()->error('Failed creating ' . $file);
            }
            fclose($fh);


            foreach($node->getResources() as $resource) {
                $file = $this->_getFileName($resource);;
                $manifest[$resource->getUri()] = [
                    'id' => $this->_getResourceId($resource),
                    'href' =>  $file,
                    'media-type' => $resource->getContentType()
                ];
                copy($resource->getAbsolutePath(), $this->getSourceDir() .  $file);
                $this->getLogger()->info('Created ' . $file);

            }

        }
    }

    #endregion
    #region - #5 Create EPUB TOC
    private function _createEpubToc(): void
    {
        $navMap = [];
        $depth = 0;
        $addToNavMap = function(NodeInterface $node, int $level) use(&$navMap, &$addToNavMap, &$depth) {
            $depth++;
            $file = $this->_getFileName($node);
            $offset = str_repeat('  ' , $level);
            $navMap[] = $offset . '    <navPoint id="'.$this->_getResourceId($node).'" playOrder="'.$node->getIndex().'">';
            $navMap[] = $offset . '      <navLabel>';
            $navMap[] = $offset . '        <text>'.$node->getMeta()->get('displayName',$node->getName()).'</text>';
            $navMap[] = $offset . '      </navLabel>';
            $navMap[] = $offset . '      <content src="'. $file.'"/>';
            foreach($node->getChildren() as $child) {
                $addToNavMap($child, $level+1);
            }
            $navMap[] = $offset . '    </navPoint>';
        };
        $addToNavMap->bindTo($this);

        foreach($this->_book->getChildren() as $child) {
            $addToNavMap($child, 0);
        }

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="utf-8"?>';
        $xml[] = '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1" xml:lang="enl">';
        $xml[] = '  <head>';
        $xml[] = '    <meta name="dtb:uid" content="'.$this->_bookId.'"/>';
        $xml[] = '    <meta name="dtb:depth" content="'.$depth.'"/>';
        $xml[] = '    <meta name="dtb:generator" content="Volta Books"/>';
        $xml[] = '    <meta name="dtb:totalPageCount" content="0"/>';
        $xml[] = '    <meta name="dtb:maxPageNumber" content="0"/>';
        $xml[] = '  </head>';
        $xml[] = '  <docTitle>';
        $xml[] = '    <text>'.$this->_book->getMeta()->get('title', $this->_book->getName()).'</text>';
        $xml[] = '  </docTitle>';
        $xml[] = '  <navMap>';
        $xml = array_merge($xml, $navMap);
        $xml[] = '  </navMap>';
        $xml[] = '</ncx>';

        $fh = fopen(  $this->getSourceDir() . $this->_tocFileName, 'w');
        fwrite($fh, trim(implode(PHP_EOL, $xml)));
        fclose($fh);
        $this->getLogger()->info('Created '. $this->_tocFileName, [__METHOD__]);

    }
    #endregion
    #region - #6 Add resources to EPUB

    private function _addResources():void
    {
        // style sheet
        $cssContent = (is_file($this->_style)) ? file_get_contents($this->_style) : '';
        $fh = fopen($this->getSourceDir() . 'epub-book.css', 'w');
        fwrite($fh, $cssContent);
        fclose($fh);
        $this->getLogger()->info("Added style");

        // cover file if anny otherwise generate default
        $coverFile = $this->_book->getChild('/cover.png');
        if (NULL !== $coverFile) {
            $this->getLogger()->debug('Found cover @ ' . $coverFile->getAbsolutePath());
        } else {
            $coverFile = realpath(__DIR__ . '/../../../../public/assets/media/cover.png');
        }

        if(copy($coverFile->getAbsolutePath(),$this->getSourceDir() . 'cover.png')) {
            $this->getLogger()->info('Successfully copied cover file');
        }
    }

    #endregion
    #region - #7 Compress data and zip to epub

    /**
     *  NOTE:
     *    On Windows I tried to do it with the 'tar' command, but it adds the absolute path in the epub and I can not
     *    find how to change them and make all paths relative. If done manually calibre still complains
     *    it is not in the right zip format. So on a Window's machine you should use something like 7-zip to compress
     *    the files and change the file extension to 'epub'
     */
    private function _zipIt(): void
    {
        $epubFileName = $this->_book->getName()  . '.epub';
        if(is_file( $this->getDestination() . $epubFileName)) unlink(__DIR__ . DIRECTORY_SEPARATOR . $epubFileName);
        $this->getlogger()->info("Try to Creat epub file " . $this->getDestination() . $epubFileName . " from  " . $this->getSourceDir());
        $cmd = "cd {$this->getSourceDir()}; pwd; zip -r ../{$epubFileName} .";
        $this->getlogger()->info($cmd);
        echo shell_exec($cmd);
    }

    #endregion
    #region - helpers

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        if(!isset($this->logger)) {
            $this->logger = new NullLogger();
        }
        return $this->logger;
    }

    private function _getResourceId(NodeInterface $node): string
    {
        return 'V' .sha1($node->getUri());
    }

    /**
     * @param NodeInterface $node
     * @return string
     */
    private function _getFileName(NodeInterface $node):string
    {
        if ($node->isDocument() ) {
            if (!is_dir($this->getSourceDir() . $this->_getContentDir() . $node->getRelativePath())) {
                mkdir($this->getSourceDir() . $this->_getContentDir() . $node->getRelativePath(), 0777, true);
                //$this->getLogger()->debug('Created ' . $this->_getContentDir() . $node->getRelativePath());
            }
            $name = str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $this->_getContentDir() . trim($node->getRelativePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'content.xhtml');

        } else {
            if (!is_dir($this->getSourceDir() . $this->_getContentDir() . dirname($node->getRelativePath()))) {
                mkdir($this->getSourceDir() . $this->_getContentDir() . dirname($node->getRelativePath()), 0777, true);
                //$this->getLogger()->debug('Created ' . $this->_getContentDir() . $node->getRelativePath());
            }
            $name = str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $this->_getContentDir() . trim($node->getRelativePath(), DIRECTORY_SEPARATOR));
        }
        return $name ;
    }

    /**
     * Formats the name of the content directory
     *
     * @return string
     */
    private function _getContentDir(): string
    {
        return $this->_contentDir . DIRECTORY_SEPARATOR;
    }

    #endregion
}