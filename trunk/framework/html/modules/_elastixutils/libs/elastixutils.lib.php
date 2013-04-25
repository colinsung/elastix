<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Elastix version 1.0-16                                               |
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
*/

/**
 * Función para obtener un detalle de los rpms que se encuentran instalados en el sistema.
 *
 *
 * @return  mixed   NULL si no se reconoce usuario, o el DNS con clave resuelta
 */
function obtenerDetallesRPMS()
{
    $packageClass = array(
        'Kernel'    =>  NULL,
        'Elastix'   =>  array('elastix*'),
        'RoundCubeMail'  =>  array('RoundCubeMail'),
        'Mail'          =>  array('postfix', 'cyrus-imapd'),
        'IM'            =>  array('openfire'),
        'FreePBX'       =>  array('freePBX'),
        'Asterisk'      =>  array('asterisk', 'asterisk-perl', 'asterisk-addons'),
        'FAX'           =>  array('hylafax', 'iaxmodem'),
        'DRIVERS'       =>  array('dahdi', 'rhino', 'wanpipe-util'),
        
    );
    $sCommand = 'rpm -qa  --queryformat "%{name} %{version} %{release}\n"';
    foreach ($packageClass as $packageLists) {
    	if (is_array($packageLists)) $sCommand .= ' '.implode(' ', array_map('escapeshellarg', $packageLists));
    }
    $output = $retval = NULL;
    exec($sCommand, $output, $retval);
    $packageVersions = array();
    foreach ($output as $s) {
    	$fields = explode(' ', $s);
        $packageVersions[$fields[0]] = $fields;
    }
    
    $result = array();
    foreach ($packageClass as $sTag => $packageLists) {
    	if (!isset($result[$sTag])) $result[$sTag] = array();
        if ($sTag == 'Kernel') {
    		// Caso especial
            $result[$sTag][] = explode(' ', trim(`uname -s -r -i`));
    	} elseif ($sTag == 'Elastix') {
    		// El paquete elastix debe ir primero
            if (isset($packageVersions['elastix']))
                $result[$sTag][] = $packageVersions['elastix'];
            foreach ($packageVersions as $packageName => $fields) {
            	if (substr($packageName, 0, 8) == 'elastix-')
                    $result[$sTag][] = $fields;
            }
    	} else {
    		foreach ($packageLists as $packageName)
                $result[$sTag][] = isset($packageVersions[$packageName])
                    ? $packageVersions[$packageName]
                    : array($packageName, '(not installed)', ' ');
    	}
    }
    return $result;
}

function setUserPassword()
{
    include_once "libs/paloSantoACL.class.php";

    $old_pass   = getParameter("oldPassword");
    $new_pass   = getParameter("newPassword");
    $new_repass = getParameter("newRePassword");
    $arrResult  = array();
    $arrResult['status'] = FALSE;
    if($old_pass == ""){
      $arrResult['msg'] = _tr("Please write your current password.");
      return $arrResult;
    }
    if($new_pass == "" || $new_repass == ""){
      $arrResult['msg'] = _tr("Please write the new password and confirm the new password.");
      return $arrResult;
    }
    if($new_pass != $new_repass){
      $arrResult['msg'] = _tr("The new password doesn't match with retype new password.");
      return $arrResult;
    }

    $user = isset($_SESSION['elastix_user'])?$_SESSION['elastix_user']:"";
    global $arrConf;
    $pdbACL = new paloDB($arrConf['elastix_dsn']['elastix']);
    $pACL = new paloACL($pdbACL);
    $uid = $pACL->getIdUser($user);
    if($uid===FALSE)
        $arrResult['msg'] = _tr("Please your session id does not exist. Refresh the browser and try again.");
    else{
        // verificando la clave vieja
        $val = $pACL->authenticateUser ($user, md5($old_pass));
        if($val === TRUE){
            $status = $pACL->changePassword($uid, md5($new_pass));
            if($status){
                $arrResult['status'] = TRUE;
                $arrResult['msg'] = _tr("Elastix password has been changed.");
                $_SESSION['elastix_pass'] = md5($new_pass);
            }else{
                $arrResult['msg'] = _tr("Impossible to change your Elastix password.");
            }
        }else{
            $arrResult['msg'] = _tr("Impossible to change your Elastix password. User does not exist or password is wrong");
        }
    }
    return $arrResult;
}

?>