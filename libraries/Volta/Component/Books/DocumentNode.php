<?php
/*
 * This file is part of the Volta package.
 *
 * (c) Rob Demmenie <rob@volta-server-framework.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Volta\Component\Books;

use DirectoryIterator;
use Volta\Component\Books\Exceptions\DocumentNodeException;
use Volta\Component\Books\Exceptions\Exception;
use Volta\Component\Books\Exceptions\ResourceNodeException;

/**
 * A DocumentNode represents a page in the book
 */
class DocumentNode extends Node
{

    /**
     * @return int
     * @throws Exception
     */
    public function getIndex(): int
    {
        foreach($this->getRoot()->getList() as $index => $node) {
            if ($node->getAbsolutePath() === $this->getAbsolutePath()) {
                return $index;
            }
        }
        return 0;
    }

    /**
     * @var Meta
     */
    protected Meta $_meta;

    /**
     * @return Meta
     * @throws Exception
     */
    public function getMeta(): Meta
    {
        if (!isset($this->_meta)) {
            $this->_meta = new Meta($this->getAbsolutePath() . DIRECTORY_SEPARATOR . 'meta.json');
        }
        return $this->_meta;

    }

    /**
     * @var array<string, NodeInterface>
     */
    protected array $_children;

    /**
     * @return array<string, NodeInterface>
     */
    public function getChildren(): array
    {
        if (!isset($this->_children)) {
            $this->_children = [];
            $dir  = new DirectoryIterator($this->getAbsolutePath());
            foreach($dir as $fileInfo) {
                if ($fileInfo->isDot()) continue;
                if (!$fileInfo->isDir()) continue;
                try {
                    $child = Node::factory($fileInfo->getPathname());
                    $this->_children[$child->getAbsolutePath()] = $child;
                } catch (Exception|DocumentNodeException|ResourceNodeException $e) {
                    continue;
                }
            }
            ksort($this->_children);
        }
        return $this->_children;
    }

    /**
     * @var NodeInterface|null Lazy load memory cache
     */
    protected null|NodeInterface $_next;

    /**
     * @return NodeInterface|null
     * @throws Exception
     */
    public function getNext(): null|NodeInterface
    {
        // lazy load, do cache the result
        if (!isset($this->_next)) {
            $this->_next = null;
            $next = false;
            foreach($this->getRoot()->getList() as $node) {
                if ($next) {
                    $this->_next = $node;
                    break;
                }
                $next = ($node->getAbsolutePath() === $this->getAbsolutePath());
            }
        }
        return $this->_next;
    }

    /**
     * @var NodeInterface|null
     */
    protected null|NodeInterface $_previous;

    /**
     * @return NodeInterface|null
     * @throws Exception
     */
    public function getPrevious(): null|NodeInterface
    {
        if (!isset($this->_previous)) {
            $this->_previous = null;
            foreach($this->getRoot()->getList() as $node) {
                if ($this->_previous === null && $this->getAbsolutePath() === $node->getAbsolutePath()) break;
                if ($this->getAbsolutePath() === $node->getAbsolutePath()) break;
                $this->_previous = $node;
            }
        }
        return $this->_previous;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getContent(): string
    {
        return $this->_getContentParser()->getContent($this->getContentFile(), $this);
    }

    /**
     * @ignore (Do not show up in generated documenation)
     * @var string $_contentFile Internal storage for the name of the content file
     */
    protected string $_contentFile;

    /**
     * Returns the name of the content file
     *
     * @return string The name of the Content File
     */
    public function getContentFile(): string
    {
        if(!isset($this->_contentFile)) {
            $result = glob( $this->getAbsolutePath() . DIRECTORY_SEPARATOR . 'content.*');
            return $this->_contentFile = $result[0];

            // NOTE:
            //     Document object cannot be instantiated without a content file present,
            //     so there's no need to test for its existence.
            //     @see Repository::getDocument()
        }
        return $this->_contentFile;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getContentType(): string
    {
        return $this->_getContentParser()->getContentType();
    }


    /**
     * @return ContentParserInterface
     * @throws Exception
     */
    protected function _getContentParser(): ContentParserInterface
    {
        $contentFile =  $this->getContentFile();
        $extension = pathinfo($contentFile, PATHINFO_EXTENSION);
        $contentParser =  Settings::getContentParserFor($extension);
        if (false === $contentParser) {
            throw new Exception(sprintf('No Content Parser found for *.%s files', $extension));
        }
        return $contentParser;
    }

    public function getModificationTime(): int|false
    {
        return filemtime($this->getContentFile());
    }

}