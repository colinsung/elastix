<?php
  /* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Elastix version 2.0.0-7                                               |
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
  $Id: index.php,v 1.1 2009-12-14 02:12:12 Oscar Navarrete J. onavarrete@palosanto.com Exp $ */
//include elastix framework
include_once "libs/paloSantoGrid.class.php";
include_once "libs/paloSantoForm.class.php";
include_once "libs/paloSantoFax.class.php";

function _moduleContent(&$smarty, $module_name)
{
    //include module files
    include_once "modules/$module_name/configs/default.conf.php";
    include_once "modules/$module_name/libs/paloSantoSendFax.class.php";

    //include file language agree to elastix configuration
    //if file language not exists, then include language by default (en)
    $lang=get_language();
    $base_dir=dirname($_SERVER['SCRIPT_FILENAME']);
    $lang_file="modules/$module_name/lang/$lang.lang";
    if (file_exists("$base_dir/$lang_file")) include_once "$lang_file";
    else include_once "modules/$module_name/lang/en.lang";

    //global variables
    global $arrConf;
    global $arrConfModule;
    global $arrLang;
    global $arrLangModule;
    $arrConf = array_merge($arrConf,$arrConfModule);
    $arrLang = array_merge($arrLang,$arrLangModule);

    //folder path for custom templates
    $templates_dir=(isset($arrConf['templates_dir']))?$arrConf['templates_dir']:'themes';
    $local_templates_dir="$base_dir/modules/$module_name/".$templates_dir.'/'.$arrConf['theme'];

    //conexion resource
    $pDB = new paloDB($arrConf['dsn_conn_database']);

    //actions
    $action = getAction();
    $content = "";

    switch($action){
        case "save_new":
            $content = sendNewSendFax($smarty, $module_name, $local_templates_dir, $pDB, $arrConf, $arrLang);
            break;
        default: // view_form
            $content = viewFormSendFax($smarty, $module_name, $local_templates_dir, $pDB, $arrConf, $arrLang);
            break;
    }
    return $content;
}

function viewFormSendFax($smarty, $module_name, $local_templates_dir, &$pDB, $arrConf, $arrLang)
{
    $pSendFax = new paloSantoSendFax($pDB);
    $pFaxList = new paloFax($pDB);

    $faxes      = $pFaxList->getFaxList();
    $arrFaxList = array("none"=>'-- '.$arrLang["Select a Fax Device"].' --');
    foreach($faxes as $values){
        $arrFaxList["ttyIAX".$values['dev_id']] = $values['clid_name']." / ".$values['extension'];
    }

    $arrFormSendFax = createFieldForm($arrLang, $arrFaxList);
    $oForm = new paloForm($smarty,$arrFormSendFax);

    //begin, Form data persistence to errors and other events.
    $_DATA  = $_POST;
    $action = getParameter("action");
    $id     = getParameter("id");
    $smarty->assign("ID", $id); //persistence id with input hidden in tpl


    if($action=="view")
        $oForm->setViewMode();
    else if($action=="view_edit" || getParameter("save_edit"))
        $oForm->setEditMode();
    //end, Form data persistence to errors and other events.

    if($action=="view" || $action=="view_edit"){ // the action is to view or view_edit.
        $dataSendFax = $pSendFax->getSendFaxById($id);
        if(is_array($dataSendFax) & count($dataSendFax)>0)
            $_DATA = $dataSendFax;
        else{
            $smarty->assign("mb_title", $arrLang["Error get Data"]);
            $smarty->assign("mb_message", $pSendFax->errMsg);
        }
    }
    //Lo q hace es ckeckear por default Text Information
    if(isset($_POST['option_fax']) && $_POST['option_fax']=='by_file')
        $smarty->assign("check_file", "checked");
    else
        $smarty->assign("check_text", "checked");

    $smarty->assign("SEND", $arrLang["Send"]);
    $smarty->assign("EDIT", $arrLang["Edit"]);
    $smarty->assign("CANCEL", $arrLang["Cancel"]);
    $smarty->assign("REQUIRED_FIELD", $arrLang["Required field"]);
    $smarty->assign("icon", "/modules/$module_name/images/fax_virtual_fax_send_fax.png");
    //News
    $smarty->assign("file_upload", $arrLang["File Upload"]);
    $smarty->assign("text_area", $arrLang["Text Information"]);
    $smarty->assign("record_Label", $arrLang["Select the files to FAX"]);
	$smarty->assign("type_files", $arrLang["Types of files"]);

    $htmlForm = $oForm->fetchForm("$local_templates_dir/form.tpl",$arrLang["Send Fax"], $_DATA);
    $content = "<form  method='POST' enctype='multipart/form-data' style='margin-bottom:0;' action='?menu=$module_name'>".$htmlForm."</form>";

    return $content;
}

function sendNewSendFax($smarty, $module_name, $local_templates_dir, &$pDB, $arrConf, $arrLang)
{
    $pSendFax = new paloSantoSendFax($pDB);
    $pFaxList = new paloFax($pDB);
    $ruta_archivo = "";
    $faxes      = $pFaxList->getFaxList();
    $arrFaxList = array("none"=>'-- '.$arrLang["Select a Fax Device"].' --');
    foreach($faxes as $values){
        $arrFaxList["ttyIAX".$values['dev_id']] = $values['clid_name']." / ".$values['extension'];
    }

    $arrFormSendFax = createFieldForm($arrLang, $arrFaxList);
    $oForm = new paloForm($smarty,$arrFormSendFax);

    if(!$oForm->validateForm($_POST)){
        // Validation basic, not empty and VALIDATION_TYPE 
        $smarty->assign("mb_title", $arrLang["Validation Error"]);
        $arrErrores = $oForm->arrErroresValidacion;
        $strErrorMsg = "<b>{$arrLang['The following fields contain errors']}:</b><br/>";
        if(is_array($arrErrores) && count($arrErrores) > 0){
            foreach($arrErrores as $k=>$v)
                $strErrorMsg .= "$k, ";
        }
        $smarty->assign("mb_message", $strErrorMsg);
        return $content = viewFormSendFax($smarty, $module_name, $local_templates_dir, $pDB, $arrConf, $arrLang);
    }
    else{
        $destine      = getParameter("to");
        $faxexten     = getParameter("from");
        $data_content = getParameter("body");

        if($faxexten == "none"){
            $smarty->assign("mb_title", $arrLang["Validation Error"]);
            $smarty->assign("mb_message", $arrLang["Please, select a valid Fax Device"]);
            return $content = viewFormSendFax($smarty, $module_name, $local_templates_dir, $pDB, $arrConf, $arrLang);
        }


        if(getParameter("option_fax")=="by_file"){
            if(is_uploaded_file($_FILES['file_record']['tmp_name'])) {
                $ruta_archivo = "/tmp/".$_FILES['file_record']['name'];
                // Validation of type file
                $ext = explode(".",$_FILES['file_record']['name']);
                $size_ext = count($ext) - 1;
				if(strtolower($ext[$size_ext])!="pdf" && $ext[$size_ext]!="tiff" && strtolower($ext[$size_ext])!="tif" && strtolower($ext[$size_ext])!="txt"){
                    $smarty->assign("mb_title", $arrLang["Validation Error"]);
                    $smarty->assign("mb_message", $arrLang["Wrong type of file"]);
                    return $content = viewFormSendFax($smarty, $module_name, $local_templates_dir, $pDB, $arrConf, $arrLang);
                }
                // Done by Ivette
                copy($_FILES['file_record']['tmp_name'], $ruta_archivo);
            }
            else{
                $smarty->assign("mb_title", $arrLang["Validation Error"]);
                $smarty->assign("mb_message", $arrLang["File to upload is empty"]);
                return $content = viewFormSendFax($smarty, $module_name, $local_templates_dir, $pDB, $arrConf, $arrLang);
            }
        }else{
            if(!empty($data_content) || $data_content!=null){
                $ruta_archivo = '/tmp/data_'.time().'.txt';
                $fh = fopen($ruta_archivo, 'w');
                fwrite($fh, $data_content);
                fclose($fh);
            }
            else{
                $smarty->assign("mb_title", $arrLang["Validation Error"]);
                $smarty->assign("mb_message", $arrLang["Text to send is empty"]);
                return $content = viewFormSendFax($smarty, $module_name, $local_templates_dir, $pDB, $arrConf, $arrLang);
            }
        }
    
        $pSendFax->sendFax($faxexten, $destine, $ruta_archivo);
        exec("rm -rf $ruta_archivo");
        $smarty->assign("SEND_FAX_SUCCESS",_tr('Fax has been sent correctly'));
        $smarty->assign("SENDING_FAX",_tr('Sending Fax...'));
          //Notification Sucessfull
        //$smarty->assign("mb_title", $arrLang["Notification Sucessfull"].": ");
        //$smarty->assign("mb_message", $arrLang["Fax has been sent correctly"]);
        return $content = viewFormSendFax($smarty, $module_name, $local_templates_dir, $pDB, $arrConf, $arrLang);
        //done by Ivette
    }
    //return $content;
}

function createFieldForm($arrLang, $arrFaxList)
{
    $arrFields = array(
            "to"   => array(      "LABEL"                  => $arrLang["Destination fax numbers"],
                                            "REQUIRED"               => "yes",
                                            "INPUT_TYPE"             => "TEXT",
                                            "INPUT_EXTRA_PARAM"      => array("style" => "width:300px","maxlength" =>"300"),
                                            "VALIDATION_TYPE"        => "numeric",
                                            "VALIDATION_EXTRA_PARAM" => ""
                                            ),
            "from"   => array(      "LABEL"                  => $arrLang["Fax Device to use"],
                                            "REQUIRED"               => "yes",
                                            "INPUT_TYPE"             => "SELECT",
                                            "INPUT_EXTRA_PARAM"      => $arrFaxList,
                                            "VALIDATION_TYPE"        => "text",
                                            "VALIDATION_EXTRA_PARAM" => ""
                                            ),
            "body"   => array(      "LABEL"                  => $arrLang["Text to FAX"],
                                            "REQUIRED"               => "no",
                                            "INPUT_TYPE"             => "TEXTAREA",
                                            "INPUT_EXTRA_PARAM"      => array("cols" => "80", "rows" => "12"),
                                            "VALIDATION_TYPE"        => "text",
                                            "EDITABLE"               => "si",
                                            "COLS"                   => "50",
                                            "ROWS"                   => "4",
                                            "VALIDATION_EXTRA_PARAM" => ""
                                            ),
            );
    return $arrFields;
}

function getAction()
{
    if(getParameter("save_new")) //Get parameter by POST (submit)
        return "save_new";
    else if(getParameter("save_edit"))
        return "save_edit";
    else if(getParameter("delete")) 
        return "delete";
    else if(getParameter("new_open")) 
        return "view_form";
    else if(getParameter("action")=="view")      //Get parameter by GET (command pattern, links)
        return "view_form";
    else if(getParameter("action")=="view_edit")
        return "view_form";
    else
        return "report"; //cancel
}
?>
