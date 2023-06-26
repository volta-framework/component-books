<?php 
/**
 * -----------------------------------------------------------------------------
 *   This program is license under MIT License.
 * 
 *   You should have received a copy of the MIT License with this program 
 *   in the file LICENSE.txt and is available through the world-wide-web 
 *   at http://license.digicademy.nl/mit-license.
 * 
 *   If you did not receive a copy of the MIT License and are unable to obtain  
 *   it through the world-wide-web please send a note to 
 * 
 *      Rob <rob@jaribio.nl> 
 * 
 *   so we can mail you a copy immediately.
 * 
 *   @license ~/LICENSE.txt
 * ----------------------------------------------------------------------------- 
 */ 
declare(strict_types=1);

namespace Volta\Component\Books\ContentParsers\XhtmlParser\Elements;

use Volta\Component\Books\ContentParsers\XhtmlParser\Element as BaseElement;

/**
 * DigiCademy Repository XHTML tag
 * 
 * The following XHTML structure:
 *
 * ```xml
 * <code>
 *    <some-programming-language>
 *       // code here
 *    </some-programming-language>
 *    <another-programming-language>
 *       // code here
 *    </another-programming-language>
 * </code>
 * ```
 *
 * Will be translated into:
 *
 * ```html
 * <div class="tab-container" id="tab-container-id-%d"></div>
 *    <em>some-programming-language</em>
 *    <pre>
 *       <code>
 *       // code here
 *      </code>
 *    </pre>
 *    <em>another-programming-language</em>
 *    <pre>
 *        <code>
 *       // code here
 *       </code>
 *     </pre>
 * </code>
 * ```
 *
 * when the Element Classes "some-programming-language" and "another-programming-language" exists in this
 * namespace as dependents this class will translate the XHTML &lt;some-programming-language&gt; and
 * &lt;another-programming-language&gt; Elements into the following HTML elements
 *
 * ```html
 *    <em>some-programming-language</em>
 *    <pre class="language-some-programming-language">
 *       <code>
 *       // code here
 *       </code>
 *    </pre>
 *    <em>another-programming-language</em>
 *    <pre class="language-another-programming-language">
 *       <code>
 *       // code here
 *      </code>
 *    </pre>
 * ````
 *
 * @package Volta\Component\Books\ContentParsers
 * @author Rob <rob@jaribio.nl> 
 */
class Language extends BaseElement
{  

    /**
     * @ignore(do not show up in the generated documentation)
     * @return string
     */
    private function _getShortName():   string
    {
        return (new \ReflectionClass($this))->getShortName();
    }


    /**
     * The caption for this Programming language.
     * Defaults to the (shortname) name of this class.
     * @return string
     */
    public function getCaption(): string
    {
        return $this->_getShortName();
    }


    /**
     * The Language
     * Defaults to the (shortname) name of this class.
     * @return string
     */
    public function getLanguage(): string
    {
        return $this->_getShortName();
    }
     

    /**
     * @see BaseElement->onTranslateStart();
     */
    public function onTranslateStart(): string
    { 
        $html  = PHP_EOL .'<em>' . $this->getCaption() . '</em>';
        $html .= PHP_EOL .'<pre><code class="language-'. strtolower($this->getLanguage()) .'">';
        return $html;
    }

    /**
     * @see BaseElement->onTranslateEnd();
     */
    public function onTranslateEnd(): string
    {   
        return '</code></pre>';
    }

} // class