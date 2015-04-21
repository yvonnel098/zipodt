<?php
/**
 * ZIPODT Plugin: Download a zip of files in all namespace contained within
 * the current namespace rendered in odt format
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Yvonne Lu <yvonne@leapinglaptop.com>
 * 
 * this plugin
 * - create a folder with an unique id + user name under tmpdir/zipodt
 * - store all source text files(converted to odt) located in namespace under the current $ID to the folder
 * - zip all odt files within the folder
 * - download the zipped file
 * - downloaded file is the same as the current $ID
 */
//define for debug
define ('RUN_STATUS', 'SERVER');

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');

//require_once 'ZipLib.class.php';


/**
 * Add the template as a page dependency for the caching system
 */
class action_plugin_zipodt extends DokuWiki_Action_Plugin {
    
    var $fh=NULL; //debug file handle
    var $zipname; //full path name of the zip file in tmpdir
    var $ZIP = NULL;
    var $dirname; //set to $conf[tmpdir]/zipodt/uniqid
    var $nszip;   //name of download file in http header
    
    /**
     * return some info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/info.txt');
        $fh=fopen("ziplog.txt", "a");
        fwrite("action:in getInfo now".PHP_EOL);
        fclose($fh);
    }

    function register($controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'zipconvert', array()); 
	$controller->register_hook('TEMPLATE_PAGETOOLS_DISPLAY', 'BEFORE', $this, 'addbutton', array());
    }
    
    //convert files to odt format and store it to tmpdir
     public function zipconvert(Doku_Event $event, $param) {
        global $ACT;
        global $REV;
        global $ID;
        global $conf;
        global $USERINFO;
        
        $this->showDebug('action:  in zipconvert: ACT= '.$ACT);
        $un = str_replace(" ", "-", $USERINFO['name']);
        $this->showDebug('action:  in zipconvert: user name= '.$USERINFO['name']);
        
        if (is_array($ACT)){
            return FALSE;
        }else {
            if(strpos($ACT, 'export_zipodt') === FALSE) return FALSE;
        }
        //create an uniqe directory for this conversion.
        //no cashing yet
        //if no zipodt under tmpdir, create it
        if(!(is_dir($this->file_build_path(array($conf["tmpdir"], "zipodt"))))) 
                mkdir($this->file_build_path(array($conf["tmpdir"], "zipodt")));
 
       
        $this->dirname=$this->file_build_path(array($conf["tmpdir"], "zipodt", uniqid().$un));
        $this->showDebug('action:  dirname= '.$this->dirname);
        mkdir($this->dirname);
        
        $this->nszip = str_replace(":", "-", $ID).".zip";
        $this->zipname = $this->file_build_path(array($this->dirname, $this->nszip));
        $this->showDebug('action:  zipname= '.$this->zipname);
        
        
        $this->getODTFiles(); //collect and convert files into the tmp directory
        $this->zipFiles(); //zip files with zipLib
        $this->sendFile(); //download with http header
        $this->showDebug('action: after sendFile before io_rm_rf');
        $this->io_rm_rf($this->dirname); //remove stuff in tmp 
        
        if ($this->fh!=NULL){
            fclose($this->fh);
        }
        return true;
     }
     
     //use odt plugin to render the files in sub namespace then store in tmpdir
     protected function getODTFiles() 
     {  global $conf;
        global $ID;
        global $INFO;
        
        //get namespace
        $org_ID=$ID; //save original ID
        
        $ns=$INFO['namespace'];
        $this->showDebug('ID= '.$ID);
        $this->showDebug('namespace= '.$ns);
        
        
        
        $dir = utf8_encodeFN(str_replace(':', '/', $ns));
        $this->showDebug('dir='.$dir);
        
        $this->setup($this->file_build_path(array($conf["datadir"], $dir)),
                     $this->dirname, $ns, 0);
        
      
        
       
        return true;
     }
     //lookindir - look into the directory
     //creatindir - directory to create sub directory in
     //orgns - namespace of the parent (to make ID to check read permission)
     //level - recursion level
     protected function setup($lookindir, $creatindir, $orgns, $level){
        global $ID; 
        
        
        $this->showDebug("entering setup orgns=".$orgns." level=".$level);  
        $this->showDebug("lookindir=".$lookindir); 
        $this->showDebug("creatindir=".$creatindir); 
        
        foreach (new DirectoryIterator($lookindir) as $file) {
            if ($file->isDir() && !$file->isDot()) {
                $this->showDebug('setup:  directory found:  '.$file->getFilename());
                $newlpath = $this->file_build_path(array($lookindir, $file->getFilename()));
                $newcpath = $this->file_build_path(array($creatindir, $file->getFilename()));
                $this->showDebug('setup:  making new dir '.$newcpath);
                mkdir ($newcpath);
                $newlvl = $level+1;
                $this->setup($newlpath, $newcpath, $orgns.":".$file->getFilename(), $newlvl);
            }else if ($file->isFile()){                
                if ($level>0){
                    if ($file->getExtension()==="txt"){
                        $newid=$orgns.":".basename($file->getFilename(),".txt");
                        if(!(auth_quickaclcheck($newid) < AUTH_READ)){
                            $this->showDebug('can read '.$lookindir.': '.$file->getFilename());
                            $this->showDebug('newid='.$newid);
                            
                            $pagehtml = p_cached_output(wikiFN($newid, ""), 'odt', $newid);
                           
                            $odtname = basename($file->getFilename(),".txt").".odt";
                            $this->showDebug("odt name=".$odtname." about to put content");
                            file_put_contents(
                                    $this->file_build_path(array($creatindir, $odtname)),
                                    $pagehtml);
                        } 
                            
                    } 
                }
            }
        }
        $this->showDebug("exiting setup");
        
         
     }
     protected function zipFiles() {
         global $conf;
         
         
         $this->ZIP = new ZipLib();
         //note:  parent/folder shows in zip file
         //$this->ZIP->Compress(basename($this->dirname) , $basedir=$conf["tmpdir"], $parent="zipodt/");
         $this->ZIP->Compress(basename($this->dirname) , $conf["tmpdir"]."/zipodt", "");
         
        
         
         $zfh=fopen($this->zipname, "wb");
         fwrite($zfh, $this->ZIP->get_file());
         fclose($zfh);
         
         
         
     }
    
     protected function sendFile() {
         global $conf;
        $filename = $this->nszip;
        $filepath = $this->dirname."//";

        // http headers for zip downloads
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header("Content-Description: File Transfer");
        header("Content-type: application/octet-stream");
        //can also be
        //header("Content-type: application/zip");
        header("Content-Disposition: attachment; filename=\"".$filename."\""); 
        //note: filename is the name that will be downloaded
        header("Content-Transfer-Encoding: binary"); 
        header("Content-Length: ".filesize($filepath.$filename)); 
        ob_end_flush(); 
        @readfile($filepath.$filename); 
    }
    
     /**
     * Recursively deletes a directory (equivalent to the "rm -rf" command)
     * Found in comments on http://www.php.net/rmdir
     */
     protected function io_rm_rf($f) {
        $this->showDebug('io_rm_rf '.$f); 
        if (is_dir($f)) {
            foreach(glob($f.'/*') as $sf) {
                if (is_dir($sf) && !is_link($sf)) {
                    $this->io_rm_rf($sf);
                } else {
                    $this->showDebug('about to delete '.$sf);
                    unlink($sf);
                }
            }
        } else { // avoid nasty consequenses if something wrong is given
            die("Error: not a directory - $f");
        }
        rmdir($f);
     }
     
    
     /**
     * Set error notification and reload page again
     *
     * @param Doku_Event $event
     * @param string     $msglangkey key of translation key
     */
    private function showPageWithErrorMsg(Doku_Event $event, $msglangkey) {
        msg($this->getLang($msglangkey), -1);

        $event->data = 'show';
        $_SERVER['REQUEST_METHOD'] = 'POST'; //clears url
    }
    
    private function showDebug($data) {
        if (strcmp(RUN_STATUS, 'DEBUG')==0){
            if ($this->fh==NULL) {
                $this->fh=fopen("ziplog.txt", "a");
            }
            fwrite($this->fh, $data.PHP_EOL);
        }
        

        
    }
    
    
    
    /**
     * Add 'export odt'-button to pagetools
     *
     * @param Doku_Event $event
     * @param mixed      $param not defined
     */
    function addbutton(&$event, $param) {
        global $ID, $REV, $conf;

        if($this->getConf('showexportbutton') && $event->data['view'] == 'main') {
            $params = array('do' => 'export_zipodt');
            if($REV) $params['rev'] = $REV;

            switch($conf['template']) {
                case 'dokuwiki':
                case 'arago':
                    $event->data['items']['export_zipodt'] =
                        '<li>'
                        .'<a href='.wl($ID, $params).'  class="action export_zipodt" rel="nofollow" title="'.$this->getLang('export_zip_button').'">'
                        .'<span>'.$this->getLang('export_zip_button').'</span>'
                        .'</a>'
                        .'</li>';
                    break;
            }
        }
    }
    
    private function file_build_path($segments) {
        return join(DIRECTORY_SEPARATOR, $segments);
        //return join('/', $segments);
    }

    

}

//Setup VIM: ex: et ts=4 enc=utf-8 :
