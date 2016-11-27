<?php
/**
 * @copyright © 2016, Vitaliy Filippov
 * @version 1.0 (2016-11-27)
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, version 2
 * of the License.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * See the GNU General Public License for more details.
 */

if (!defined('MEDIAWIKI'))
    die("This requires the MediaWiki enviroment.");

$wgExtensionCredits['parserhook'][] = array(
    'name'        => 'RequestMagic',
    'author'      => 'Vitaliy Filippov',
    'description' => 'Cacheable {{#request: }} parser function',
    'url'         => 'http://wiki.4intra.net/RequestMagic',
    'version'     => '1.0',
);

$wgExtensionMessagesFiles['RequestMagic'] = dirname(__FILE__) . '/RequestMagic.i18n.php';
$wgHooks['MagicWordwgVariableIDs'][] = 'RequestMagicImpl::MagicWordwgVariableIDs';
$wgHooks['ArticleEditUpdates'][] = 'RequestMagicImpl::ArticleEditUpdates';
$wgHooks['ParserFirstCallInit'][] = 'RequestMagicImpl::ParserFirstCallInit';
$wgHooks['ParserOutputRenderKey'][] = 'RequestMagicImpl::ParserOutputRenderKey';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'RequestMagicImpl::LoadExtensionSchemaUpdates';

/**
 * USAGE:
 * - first define request variables you want to use with {{#requestparams: param1|param2|...}}
 * - then use {{#request:...}} to retrieve parameter values
 */
class RequestMagicImpl
{
    static $cachedParams = [];
    static $newParams = [];

    static function MagicWordwgVariableIDs(&$mVariablesIDs)
    {
        $mVariablesIDs[] = 'request';
        $mVariablesIDs[] = 'requestparams';
        return true;
    }

    static function ParserFirstCallInit(&$parser)
    {
        $parser->setFunctionHook('request', 'RequestMagicImpl::pf_request');
        $parser->setFunctionHook('requestparams', 'RequestMagicImpl::pf_requestparams');
        return true;
    }

    static function ArticleEditUpdates($article, $editInfo, $changed)
    {
        $dbw = wfGetDB(DB_MASTER);
        $t = $article->getTitle()->getPrefixedText();
        if (isset(self::$newParams[$t]))
        {
            $id = $article->getId();
            $dbw->delete('page_requestparams', [ 'page_id' => $id ], __METHOD__);
            $rows = [];
            foreach (self::$newParams[$t] as $k => $true)
                $rows[] = [ 'page_id' => $id, 'param_name' => $k ];
            $dbw->insert('page_requestparams', $rows, __METHOD__);
        }
        return true;
    }

    static function ParserOutputRenderKey($article, &$renderkey)
    {
        global $wgRequest;
        $cache = wfGetCache(CACHE_ANYTHING);
        $params = self::getParams($article->getTitle());
        foreach ($params as $k => $true)
        {
            $renderkey .= '|'.str_replace('|', '||', $wgRequest->getText($k));
        }
        if ($params)
            $renderkey .= '|';
        return true;
    }

    protected static function getParams($title)
    {
        $t = $title->getPrefixedText();
        if (isset(self::$newParams[$t]))
        {
            $params = self::$newParams[$t];
        }
        elseif (isset(self::$cachedParams[$t]))
        {
            $params = self::$cachedParams[$t];
        }
        elseif (($id = $title->getArticleId()))
        {
            // try to fetch
            $rows = wfGetDB(DB_SLAVE)->select(
                'page_requestparams', 'param_name', [ 'page_id' => $id ], __METHOD__
            );
            $params = [];
            foreach ($rows as $row)
                $params[$row->param_name] = true;
            self::$cachedParams[$t] = $params;
        }
        else
            $params = [];
        return $params;
    }

    static function pf_request($parser, $param)
    {
        global $wgRequest;
        $params = self::getParams($parser->getTitle());
        if (!isset($params[$param]))
        {
            $parser->disableCache();
        }
        return $wgRequest->getText($param);
    }

    static function pf_requestparams($parser)
    {
        global $wgRequest;
        $t = $parser->getTitle()->getPrefixedText();
        if (!isset(self::$newParams[$t]))
            self::$newParams[$t] = [];
        foreach (func_get_args() as $i => $v)
            if ($i > 0)
                self::$newParams[$t][$v] = true;
        return '';
    }

    static function LoadExtensionSchemaUpdates($updater = NULL)
    {
        global $wgExtNewTables, $wgDBtype;
        $dbtype = ($updater ? $updater->getDB()->getType() : $wgDBtype);
        if ($dbtype != 'mysql' && $dbtype != 'postgres')
            die("RequestMagic only supports MySQL and PostgreSQL at the moment");
        $f1 = __DIR__.'/tables-'.$dbtype.'.sql';
        if ($updater)
            $updater->addExtensionUpdate(array('addTable', 'page_requestparams', $f1, true));
        else
            $wgExtNewTables[] = array('page_requestparams', $f1);
        return true;
    }
}
