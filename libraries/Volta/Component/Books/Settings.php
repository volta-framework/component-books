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

use Psr\Cache\CacheItemPoolInterface;
use Volta\Component\Books\ContentParsers\HtmlParser;
use Volta\Component\Books\ContentParsers\PhpParser;
use Volta\Component\Books\ContentParsers\XhtmlParser;
use Volta\Component\Books\Exceptions\Exception;

abstract class Settings
{

    private static array $_supportedResources = [
        // textual files
        'html', 'htm'  => 'text/html',
        'txt'  => 'text/plain',
        'css'  => 'text/css',
        'js'  => 'text/javascript',

        // video's
        'avi'  => 'video/x-msvideo',
        'mpeg' => 'video/mpeg',
        'mp4'  => 'video/mp4',
        'mov'  => 'video/quicktime',

        // images
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'bmp'  => 'image/bmp',
        'gif'  => 'image/gif',
        'ico'  => 'image/vnd.microsoft.icon',
        'jpeg','jpg'  => 'image/jpeg',
        'png'  => 'image/png',
    ];

    /**
     * DocumentNodes can have resources embedded in their content. But depending on the implementation a resource
     * identified by their file extension may or may not be supported in the current application.
     *
     * @param string $extension
     * @return bool True when the resource is supported false otherwise
     */
    public static function isResourceSupported(string $extension): bool
    {
        return isset(Settings::$_supportedResources[$extension]);
    }

    /**
     * If the resource identified by its file extension the corresponding mimetype is returned. False if
     * the resource is not supported
     *
     * @param string $extension
     * @return bool|string Mimetype, false if resource is not supported
     */
    public static function getResourceMimeType(string $extension): bool|string
    {
        if (Settings::isResourceSupported($extension)){
            return Settings::$_supportedResources[$extension];
        }
        return false;
    }

    /**
     * Lists all the supported resources
     *
     * @return array|string[]
     */
    public static function getSupportedResources(): array
    {
        return Settings::$_supportedResources;
    }

    /**
     * Add or overwrite a resource
     *
     * @param string $extension
     * @param string $mimeType
     * @return void
     */
    public static function setSupportedResource(string $extension, string $mimeType): void
    {
        Settings::$_supportedResources[$extension] = $mimeType;
    }

    // -----------------------------------------------------------------------------------------------------------

    /**
     * List of all content parsers. Set to private to enforce the use of the
     * registerContentParser() and getContentParserFor() methods
     *
     * @var array<string, string> Defaults to build in parsers
     */
    private static array $_contentParsers = [
        'php' => PhpParser::class,
        'phtml' => PhpParser::class,
        'xhtml' => XhtmlParser::class,
        'html' => HtmlParser::class,
        'htm' => HtmlParser::class,
    ];

    /**
     * Returns the ContentParser, false if not set for this type of file
     * @param string $extension
     * @return false|ContentParserInterface
     */
    public static function getContentParserFor(string $extension): false|ContentParserInterface
    {
        if (isset(static::$_contentParsers[$extension])) {
            $parser = static::$_contentParsers[$extension];
            return  new $parser();
        }
        return false;
    }

    /**
     * @param string $extension File extension
     * @param string $class The name of the class which must implement ContentParserInterface
     * @return bool
     * @throws Exception
     */
    public static function registerContentParser(string $extension, string $class): bool
    {
        $interfaces = class_implements($class);
        if (!isset($interfaces[ContentParserInterface::class])) {
            throw new Exception('DocumentNode Content Parser must implement ' . ContentParserInterface::class);
        }
        static::$_contentParsers[$extension] = $class;
        return true;
    }

    // -----------------------------------------------------------------------------------------------------------

    /**
     * Cache object reference Set to private to enforce the use of the
     * getCache() and setCache() methods
     *
     * @ignore Do not show up in generated documentation
     * @var CacheItemPoolInterface|null 
     */
    private static null|CacheItemPoolInterface $_cachePool = null;

    /**
     * @return CacheItemPoolInterface|null
     */
    public static function getCache(): null|CacheItemPoolInterface
    {
        return Settings::$_cachePool;
    }

    /**
     * @param CacheItemPoolInterface $cachePool
     * @return void
     */
    public static function setCache(CacheItemPoolInterface $cachePool):void
    {
        Settings::$_cachePool = $cachePool;
    }


}