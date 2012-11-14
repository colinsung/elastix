<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Elastix version 2.2.0-29                                               |
  | http://www.elastix.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | Cdla. Nueva Kennedy Calle E 222 y 9na. Este                          |
  | Telfs. 2283-268, 2294-440, 2284-356                                  |
  | Guayaquil - Ecuador                                                  |
  | http://www.palosanto.com                                             |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Original Code is: Elastix Open Source.                           |
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id: index.php,v 1.1.1.1 2012/07/30 rocio mera rmera@palosanto.com Exp $ */
    include_once "/var/www/html/libs/paloSantoACL.class.php";
    include_once "/var/www/html/libs/paloSantoAsteriskConfig.class.php";
    include_once "/var/www/html/libs/paloSantoPBX.class.php";
    global $arrConf;
    
class paloSantoMoH extends paloAsteriskDB{
    public $_DB; //conexion base de mysql elxpbx
    public $errMsg;
    protected $code;
    protected $domain;

    function paloSantoMoH(&$pDB,$domain)
    {
       parent::__construct($pDB);
        
        if(!preg_match("/^(([[:alnum:]-]+)\.)+([[:alnum:]])+$/", $domain)){
            $this->errMsg="Invalid domain format";
        }else{
            $this->domain=$domain;

            $result=$this->getCodeByDomain($domain);
            if($result==false){
                $this->errMsg .=_tr("Can't create a new instace of paloQueuePBX").$this->errMsg;
            }else{
                $this->code=$result["code"];
            }
        }
    }

    function getNumMoH($domain=null){
        $where="";
        $arrParam=null;

        if(isset($domain)){
            if(!preg_match("/^(([[:alnum:]-]+)\.)+([[:alnum:]])+$/", $domain)){
                $this->errMsg="Invalid domain format";
                return false;
            }else{
                $where="where organization_domain=?";
                $arrParam=array($domain);
            }
        }
        
        $query="SELECT count(name) from musiconhold $where";
        $result=$this->_DB->getFirstRowQuery($query,false,$arrParam);
        if($result==false){
            $this->errMsg=$this->_DB->errMsg;
            return false;
        }else
            return $result[0];
    }

    
    function getMoHs($domain=null){
        $where="";
        $arrParam=null;
        
        if(isset($domain)){
            if(!preg_match("/^(([[:alnum:]-]+)\.)+([[:alnum:]])+$/", $domain)){
                $this->errMsg="Invalid domain format";
                return false;
            }else{
                $where="where organization_domain=?";
                $arrParam=array($domain);
            }
        }

        $query="SELECT name, description, mode,directory, application, sort, organization_domain from musiconhold   $where";
                
        $result=$this->_DB->fetchTable($query,true,$arrParam);
        if($result===false){
            $this->errMsg=$this->_DB->errMsg;
            return false;
        }else
            return $result;
    }

    //debo devolver un arreglo que contengan los parametros del MoH
    function getMoHByClass($class){
        $where="";
        $arrParam=array($class);
        
        if (!preg_match('/^([[:alnum:]]|-|_)+$/', "$class")) {
            $this->errMsg = _tr("Invalid MoH Class");
            return false;
        }

        $arrCredentiasls=getUserCredentials();
        $userLevel1=$arrCredentiasls["userlevel"];
        if($userLevel1!="superadmin"){
            if($arrCredentiasls["domain"]==false){
                $this->errMsg=_tr("Invalid Organization");
                return false;
            }else{
                $this->domain=$arrCredentiasls["domain"];
                $arrParam[]=$arrCredentiasls["domain"];
                $where=" and organization_domain=?";
            }
            $directory="/var/lib/asterisk/moh/".$this->domain."/";
        }else{
            $this->domain="";
            $directory="/var/lib/asterisk/mohmp3/";
        }

        $query="SELECT name as class, description as name, mode as mode_moh,directory, application, sort, format from musiconhold where name=? $where";
        $result=$this->_DB->getFirstRowQuery($query,true,$arrParam);
        
        if($result===false){
            $this->errMsg=$this->_DB->errMsg;
            return false;
        }elseif(count($result)>0){
            $directory .=$result["name"];
            $result["listFiles"]=array();
            if($result["mode_moh"]=="files"){
                if(is_dir($directory)){
                    $arrFiles=scandir($directory);
                    foreach($arrFiles as $file){
                        if($file!="." && $file!=".."){
                            if(is_file($directory."/".$file)){
                                $result["listFiles"][]=$file;
                            }
                        }
                    }
                }
            }
            return $result;
        }else
            return $result;
    }
    
    function existMoH($class){
        $query="SELECT count(name) from musiconhold where name=?";
        $result=$this->_DB->getFirstRowQuery($query,true,$arrParam);
        if($result===false || count($result)>0){
            $this->errMsg=$this->_DB->errMsg;
            return true;
        }else{
            return false;
        }
    }
    
    /**
        funcion que crea un nueva ruta entrante dentro del sistema
    */
    function createNewMoH($arrProp){
        $query="INSERT INTO musiconhold (organization_domain,name,description,mode,directory,application,sort,format) values (?,?,?,?,?,?,?,?)";
        $arrOpt=array();
       
        $arrCredentiasls=getUserCredentials();
        $userLevel1=$arrCredentiasls["userlevel"];
        if($userLevel1=="other"){
            $this->errMsg = _tr("Invalid Action");
            return false;
        }else{
            if($userLevel1=="admin"){
                if($arrCredentiasls["domain"]==false){
                    $this->errMsg=_tr("Invalid Organization");
                    return false;
                }
                $this->domain=$arrCredentiasls["domain"];
                $result=$this->getCodeByDomain($this->domain);
                if($result==false){
                    return false;
                }
                $class=$result["code"]."_".$arrProp["name"];
            }else{
                $this->domain="";
                $class=$arrProp["name"];
            }
        }

        //debe haberse seteado un nombre
        if (!preg_match('/^([[:alnum:]]|-|_)+$/', "$class")) {
            $this->errMsg = _tr("Invalid MoH Class");
            return false;
        }
        
        if($this->existMoHClass($class)){
            $this->errMsg=_tr("Already exist another MoH Class woth the same name. ").$this->errMsg;
            return false;
        }
        
        if($arrProp["mode"]=="files"){
            $mode="files";
            $application="";
            $format="";
            $sort=$arrProp["sort"];
            if($userLevel1=="superadmin")
                $directory="/var/lib/asterisk/mohmp3/".$arrProp["name"];
            else
                $directory="/var/lib/asterisk/moh/".$this->domain."/".$arrProp["name"];
        }else{
            $mode="custom";
            if($arrProp["application"]==""){
                $this->errMsg=_tr("Field 'application' can't be empty").$this->errMsg;
                return false;
            }
            $application=$arrProp["application"];
            $format=$arrProp["format"];
            $directory="";
            $sort="";
        }
        
        
        $result=$this->executeQuery($query,array($this->domain,$class,$arrProp["name"],$mode,$directory,$application,$sort,$format));
                
        if($result==false){
            $this->errMsg=$this->errMsg;
            return false;
        }else{
            if($this->createDirMoH($arrProp["name"])==false)
                return false;
            else
                return true;
        }
    }
    
    private function createDirMoH($name_class){
        $sComando = "/usr/bin/elastix-helper asteriskconfig createMoHDir $name_class ";
        
        if($this->domain!=""){
            $sComando .=$this->domain;
        }
        $sComando .='  2>&1';
        
        $output = $ret = NULL;
        exec($sComando, $output, $ret);
        if ($ret != 0) {
            $this->errMsg = implode('', $output);
            return FALSE;
        }
        return TRUE;
    }
    
    //$name -> nombre de la clase sin prefijo dela organizacion
    function UploadFile($name){
        $where=$error="";
        $param=array($name,"files");
        
        if (!preg_match('/^([[:alnum:]]|-|_)+$/', "$name")) {
            $this->errMsg = _tr("Files can't be uploaded. ")._tr("Invalid MoH Class");
            return false;
        }
        
        $arrCredentiasls=getUserCredentials();
        $userLevel1=$arrCredentiasls["userlevel"];
        if($userLevel1=="other"){
            $this->errMsg = _tr("Files can't be uploaded. ")._tr("Invalid Action");
            return false;
        }else{
            if($userLevel1=="admin"){
                if($arrCredentiasls["domain"]==false){
                    $this->errMsg=_tr("Files can't be uploaded. ")._tr("Invalid Organization");
                    return false;
                }
                $this->domain=$arrCredentiasls["domain"];
                $where="and organization_domain=?";
                $param[]=$this->domain;
                $directory="/var/lib/asterisk/moh/".$this->domain."/".$name;
            }else{
                $this->domain="";
                $directory="/var/lib/asterisk/mohmp3/".$name;
            }
        }
        
        $query="SELECT directory from musiconhold where description=? and mode=? $where";
        $result=$this->_DB->getFirstRowQuery($query,true,$param);
        if($result===false || count($result)==0){
            $this->errMsg=_tr("Files can't be uploaded. ")._tr("MoH Class doens't exist. ").$this->_DB->errMsg;
            return false;
        }
        
        if(!is_dir($directory)){
            if($this->createDirMoH($name)==false)
                return false;
        }
        
        if (isset($_FILES['file'])) {
            $count=count($_FILES['file']['name']);
            for($i=0;$i<$count;$i++){
                if($_FILES['file']['tmp_name'][$i]!=""){
                    if (preg_match("/^(\w|-|\.|\(|\)|\s)+\.(wav|WAV|Wav|gsm|GSM|Gsm|Wav49|wav49|WAV49|mp3|MP3|Mp3)$/",$_FILES['file']['name'][$i])) {
                        if (!preg_match("/(\.php)/",$_FILES['file']['name'][$i])) {
                            $filenameTmp = $_FILES['file']['name'][$i];
                            $tmp_name = $_FILES['file']['tmp_name'][$i];
                            $filename = basename("$directory/$filenameTmp");
                            $date=date("YMd_His");
                            $tmpFile=$date."_".$filename;
                            if (move_uploaded_file($tmp_name, "$directory/$tmpFile"))
                            {
                                $info=pathinfo($filename);
                                $file_sin_ext=$info["filename"];
                                $type=$this->getTipeOfFile("$directory/$tmpFile");
                                $continue=true;
                                
                                if($type==false){
                                    $error .=$this->errMsg;
                                    $continue=false;
                                }
                                
                                if($type=="audio/mpeg; charset=binary"){
                                    if($this->convertMP3toWAV($directory,$tmpFile,$file_sin_ext,$date)==false){
                                        $error .=$this->errMsg;
                                        $continue=false;
                                    }else{
                                        $filename=$file_sin_ext.".wav";
                                    }
                                }
                                if($continue){
                                    if($this->resampleMoHFiles($directory,$tmpFile,$filename)==false)
                                        $error .=$this->errMsg;
                                }
                            }else{
                                $error .=_tr("File could be uploaded: ").$_FILES['file']['name'][$i]." \n";
                            }
                        }else{
                            $error .=_tr("Possible file upload attack: ").$_FILES['file']['name'][$i]." \n";
                        }
                    }else{
                       $error .=_tr("Possible file upload attack: ").$_FILES['file']['name'][$i]." \n";
                    }
                }
            }
        }
        
        if($error!="")
            $this->errMsg=_tr("Some files can't be uploaded. ").$error;
    }
    
    private function getTipeOfFile($file){
        $mime_type="";
        $finfo = new finfo(FILEINFO_MIME, "/usr/share/misc/magic.mgc");
        if(is_file($file)){
            $mime_type = $finfo->file($file);
        }else{
            $this->errMsg = _tr("File doens't exist ").$file;
            return false;
        }
        return $mime_type;
    }
    
    private function convertMP3toWAV($base,&$tmpFile,$file_sin_ext,$prep){
        $output = $ret = NULL;
        
        $tmp=$tmpFile;
        $tmpFile=$prep."_".$file_sin_ext.".wav";
        //mpg123 -w outputFile inputFile
        exec("mpg123 -w $base/$tmpFile $base/$tmp", $output, $ret);
        if ($ret != 0) {
            unlink("$base/$tmp");
            $this->errMsg = implode('', $output);
            return FALSE;
        }else{
            unlink("$base/$tmp");
            return TRUE;
        }
    }
    
    private function resampleMoHFiles($base,$tmpFile,$filename){
      //  sox inputFile -r 8000 -c 1 outputFile
        $output = $ret = NULL;
        exec("sox $base/$tmpFile -r 8000 -c 1 $base/$filename", $output, $ret);
        if ($ret != 0) {
            unlink("$base/$tmpFile");
            $this->errMsg = implode('', $output);
            return FALSE;
        }else{
            unlink("$base/$tmpFile");
            return TRUE;
        }
    }
    
    function updateMoHPBX($arrProp){
        $class=$arrProp["class"];
        $param=array();
        $error="";
        $arrMoH=$this->getMoHByClass($class);
        if($arrMoH==false){
            $this->errMsg=_tr("MoH class doesn't exist");
            return false;
        }
        
        $query="Update musiconhold ";
        if($arrMoH["mode_moh"]=="files"){
            $query .="set sort=?";
            $param[]=$arrProp["sort"];
        }else{
            $query .="set application=?,format=?";
            if($arrProp["application"]==""){
                $this->errMsg=_tr("Field 'application' can't be empty");
                return false;
            }
            $param[]=$arrProp["application"];
            $param[]=$arrProp["format"];
        }
        
        $query .="where name=?";
        $param[]=$class;
        
        $result=$this->executeQuery($query,$param);
                
        if($result==false){
            $this->errMsg=$this->errMsg;
            return false;
        }else{
            //revisamos los archivos a ver si ahi alguno que el usuario desse eliminar de la clase
            if($this->domain!=""){
                $directory="/var/lib/asterisk/moh/".$this->domain."/".$arrMoH["name"];
            }else
                $directory="/var/lib/asterisk/mohmp3/".$arrMoH["name"];
            
            $arrFiles=$arrMoH["listFiles"];
            $act_files=$arrProp["remain_files"];
            if(is_array($act_files)){
                $diffFiles=array_diff($arrFiles,$act_files);
                foreach($diffFiles as $file){
                    if(is_file($directory."/".$file)){
                        if(unlink($directory."/".$file)==false){
                            $error .="File $file couldn't be deleted";
                        }
                    }   
                }
            }
        }
        $this->errMsg = $error;
        return true;
    }

    function deleteMoH($class){
        $arrMoH=$this->getMoHByClass($class);
        if($arrMoH==false){
            $this->errMsg=_tr("MoH class doens't exist. ").$this->errMsg;
            return false;
        }
        
        $query="DELETE from musiconhold where name=?";
        if($this->executeQuery($query,array($class))){
            //eliminamos los archivos de audio y la carpeta correspondientes a la clase
            if($this->domain!=""){
                $directory="/var/lib/asterisk/moh/".$this->domain."/".$arrMoH["name"];
            }else
                $directory="/var/lib/asterisk/mohmp3/".$arrMoH["name"];
                
            if(is_dir($directory)){
                foreach($arrMoH["listFiles"] as $file){
                    if(is_file($directory."/".$file)){
                        if(unlink($directory."/".$file)==false)
                            break;
                    }
                }
                if(rmdir($directory)==false){
                    $this->errMsg=$directory._tr(" couldn't be deleted from system");
                    return false;
                }
            }
            return true;
        }else{
            $this->errMsg="MoH clase can't be deleted.".$this->errMsg;
            return false;
        } 
    }
   
}
?>