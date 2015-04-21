<?php
/**
 * ZIPODT RENDERER
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Yvonne Lu
 * 
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

require_once DOKU_INC.'inc/parser/renderer.php';


/**
 * The Renderer
 */
class renderer_plugin_zipodt extends Doku_Renderer {
   
    var $fh = null;
   
    /**
     * Return version info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/info.txt');
    }

    /**
     * Returns the format produced by this renderer.
     */
    function getFormat(){
        return "zip";
    }

    /**
     * Do not make multiple instances of this class
     */
    function isSingleton(){
        return true;
    }


    



}