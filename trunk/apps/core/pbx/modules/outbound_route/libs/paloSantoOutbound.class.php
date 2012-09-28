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
    include_once "libs/paloSantoACL.class.php";
    include_once "libs/paloSantoAsteriskConfig.class.php";
    include_once "libs/paloSantoPBX.class.php";
	global $arrConf;
class paloSantoOutbound extends paloAsteriskDB{
    var $_DB; //conexion base de mysql elxpbx
    var $errMsg;
    protected $code;
    protected $domain;

    function paloSantoOutbound(&$pDB,$domain)
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

    function getNumOutbound($domain=null){
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
		$query="SELECT count(id) from outbound_route $where";
		$result=$this->_DB->getFirstRowQuery($query,false,$arrParam);
        if($result==false){
			$this->errMsg=$this->_DB->errMsg;
			return false;
		}else
			return $result[0];
    }

	
	function getOutbounds($domain=null){
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

		$query="SELECT * from outbound_route $where";
                
		$result=$this->_DB->fetchTable($query,true,$arrParam);
		if($result===false){
			$this->errMsg=$this->_DB->errMsg;
			return false;
		}else
			return $result;
    }


	 function getTrunks($domain=null){
		$where="";
		$arrParam=null;

		$query="SELECT tr.trunkid, tr.name, tr.tech  from trunk as tr join trunk_organization as tor on tr.trunkid=tor.trunkid where organization_domain=?";
        
        $arrTrunk[]="";
		$result=$this->_DB->fetchTable($query,true,array($domain));
		if($result===false){
			$this->errMsg=$this->_DB->errMsg;
			return false;
		}else{
			foreach($result as $value){
				$arrTrunk[$value['trunkid']]=$value['name']."/".strtoupper($value['tech']);
			}
			return $arrTrunk;
		}
    }

    function getTrunkById($id){
		global $arrConf;
		$where="";
		if (!preg_match('/^[[:digit:]]+$/', "$id")) {
            $this->errMsg = "Trunk ID must be numeric";
		    return false;
        }
		
		$query="SELECT t.trunkid, t.name, t.tech  from trunk t where trunkid=?";
		$result=$this->_DB->getFirstRowQuery($query,true,array());

		if($result===false){
		   $this->errMsg=$this->_DB->errMsg;
		   return false;
		}else{
		  // $arrTrunk[$result["trunkid"]]=$result["name"]."/".strtoupper($result["tech"]);
		   return $result;
		}
    }    


	 
	function checkName($domain=null){
		  $where="";
		  if(getParameter("id_trunk"))
		    $id_ivr = getParameter("id_trunk");
		  else
		    $id_ivr = "";
		  $displayname = getParameter("channelid");
		  $arrParam=null;
		  if(isset($domain)){
			  if(!preg_match("/^(([[:alnum:]-]+)\.)+([[:alnum:]])+$/", $domain)){
				  $this->errMsg="Invalid domain format";
				  return false;
			  }else{
				  $where="where organization_domain=? AND trunkid<>? AND channelid=? ";
				  $arrParam=array($domain,$id_ivr,$displayname);
			  }
		  }
		  
		  $query="SELECT channelid from trunk $where";
		  
		  $result=$this->_DB->fetchTable($query,true,$arrParam);
		  if($result===false){
			  $this->errMsg=$this->_DB->errMsg;
			  return false;
		  }else{
			 if ($result==null)
			     return 0;
			 else
			     return 1;
			}
	  }

		
    function getArrDestine($idOutbound){
        $query="SELECT * from outbound_route_dialpattern WHERE outbound_route_id=? order by seq";
        $result=$this->_DB->fetchTable($query,false,array($idOutbound));
	
        if($result==false)
            $this->errMsg=$this->errMsg;
        return $result; 
	}

	function getArrTrunkPriority($idOutbound){
	      $query="SELECT t.trunkid,t.name,t.tech from outbound_route_trunkpriority o, trunk t WHERE t.trunkid=o.trunk_id AND o.outbound_route_id=? order by o.seq";
              $result=$this->_DB->fetchTable($query,false,array($idOutbound));
	      $arrTrunk = array();
	      if($result==false)
		 $this->errMsg=$this->errMsg;


		foreach($result as $value){
		    $arrTrunk[$value[0]]=$value[1]."/".strtoupper($value[2]);
		}
        return $arrTrunk; 
	}
	
        //debo devolver un arreglo que contengan los parametros del Trunk
	function getOutboundById($id,$domain=null){
		global $arrConf;
		$arrOutbound=array();
		$where="";
		if (!preg_match('/^[[:digit:]]+$/', "$id")) {
                    $this->errMsg = "Extension ID must be numeric";
		    return false;
                }

		$param=array($id);
		if(isset($domain)){
			if(!preg_match("/^(([[:alnum:]-]+)\.)+([[:alnum:]])+$/", $domain)){
				$this->errMsg="Invalid domain format";
				return false;
			}else{
				$where=" and organization_domain=?";
				$param[]=$domain;
			}
		}

		$query="SELECT routename,outcid,outcid_mode,routepass,mohsilence,time_group_id,organization_domain ";
                $query.="from outbound_route where id=? $where";
		$result=$this->_DB->getFirstRowQuery($query,true,$param);
                if($result===false){
			$this->errMsg=$this->_DB->errMsg;
			return false;
		}elseif(count($result)>0){
			$arrOutbound["routename"]=$result["routename"];
			$arrOutbound["outcid"]=$result["outcid"];
			$arrOutbound["outcid_mode"]=$result["outcid_mode"];
			$arrOutbound["routepass"]=$result["routepass"];
			$arrOutbound["mohsilence"]=$result["mohsilence"];
			$arrOutbound["time_group_id"]=$result["time_group_id"];
			$arrOutbound["domain"]=$result["organization_domain"];   			
			return $arrOutbound;
		}
		
    }
}

/*
class paloOutboundPBX extends paloAsteriskDB{
    public $type;
    protected $domain;
    protected $code;
    public $_DB;
    public $errMsg;

    function paloOutboundPBX($domain,&$pDB2){
        if(!preg_match("/^(([[:alnum:]-]+)\.)+([[:alnum:]])+$/", $domain)){
            $this->errMsg="Invalid domain format";
        }else{
            $this->domain=$domain;

            parent::__construct($pDB2);

            $result=$this->getCodeByDomain($domain);
            if($result==false){
                $this->errMsg .=_tr("Can't create a new instace of paloOutboundPBX").$this->errMsg;
            }else{
                $this->code=$result["code"];
            }
        }
    }

    function createNewOutbound($arrProp,$arrDialPattern,$arrTrunkPriority){
        $query="INSERT INTO outbound_route (";
        $arrOpt=array();

        //definimos el tipo de truncal que vamos a crear
                
        //debe haberse seteado un nombre
        if(!isset($arrProp["routename"]) || $arrProp["routename"]==""){
            $this->errMsg="Name of outbound can't be empty";
        }else{
            $val = $this->checkName($arrProp['domain'],$arrProp['routename']);
                if($val==1)
               $this->errMsg="Route Name is already used by another Outbound Route"; 
            else{
               $query .="routename,";
               $arrOpt[0]=$arrProp["routename"];
            }
        }

        //si se define un callerid 
        if(isset($arrProp["outcid"])){
            $query .="outcid,";
            $arrOpt[count($arrOpt)]=$arrProp["outcid"];
        }

        if(isset($arrProp["outcid_mode"])){
            $query .="outcid_mode,";
            $arrOpt[count($arrOpt)]=$arrProp["outcid_mode"];
        }
      
        //si se define un password
        if(isset($arrProp["routepass"])){
            $query .="routepass,";
            $arrOpt[count($arrOpt)]=$arrProp["routepass"];
        }

        
        if(isset($arrProp["mohsilence"])){
            $query .="mohsilence,";
            $arrOpt[count($arrOpt)]=$arrProp["mohsilence"];
        }

        if(isset($arrProp["time_group_id"])){
            $query .="time_group_id,";
            $arrOpt[count($arrOpt)]=$arrProp["time_group_id"];
        }

        if(!isset($arrProp["domain"]) || $arrProp["domain"]==""){
            $this->errMsg="It's necesary you create a new organization so you can create an Outbound to this organization";
        }else{
            $query .="organization_domain";
            $arrOpt[count($arrOpt)]=$arrProp["domain"];
        }

        //caller id options
        
        
        $query .=")";
        $qmarks = "(";
        for($i=0;$i<count($arrOpt);$i++){
            $qmarks .="?,"; 
        }
        $qmarks=substr($qmarks,0,-1).")"; 
        $query = $query." values".$qmarks;
        if($this->errMsg==""){
            $exito=$this->createOutbound($query,$arrOpt,$arrProp);
            
        }else{
            return false;
        }

        if($exito==true){
            //si ahi dialpatterns se los procesa
                $result = $this->getFirstResultQuery("SELECT LAST_INSERT_ID()",NULL);//{
            
                $outboundid=$result[0];
               
                if($this->createDialPattern($arrDialPattern,$outboundid)==false){
                    $this->errMsg="Outbound can't be created .".$this->errMsg;
                    return false;
                }elseif($this->createTrunkPriority($arrTrunkPriority,$outboundid,$arrProp["domain"])==false){
                    $this->errMsg="Outbound can't be created .".$this->errMsg;
                    return false;
                }else
                    return true;
            //}
        }else
            return false;

    }

    private function createOutbound($query,$arrOpt,$arrProp){
        if(!isset($arrProp["routename"]) || $arrProp["routename"]==""){
            $this->errMsg="Outbound can't be created. Route Name can't be empty";
            return false;
        }
        $result=$this->executeQuery($query,$arrOpt);
        
        
        if($result==false)
            $this->errMsg=$this->errMsg;
        return $result; 

        //validamos que no existe otros peers con el mismo nombre de truncal
        
    }

    function updateOutboundPBX($arrProp,$arrDialPattern,$idOutbound,$arrTrunkPriority){
        $query="UPDATE outbound_route SET ";
        $arrOpt=array();

        //definimos el tipo de truncal que vamos a crear
                
        //debe haberse seteado un nombre
        if(!isset($arrProp["routename"]) || $arrProp["routename"]==""){
            $this->errMsg="Name of outbound can't be empty";
        }else{
            $val = $this->checkName($arrProp['domain'],$arrProp['routename'],$idOutbound);
                if($val==1)
               $this->errMsg="Route Name is already used"; 
            else{
                $query .="routename=?,";
                $arrOpt[0]=$arrProp["routename"];
            }
        }

        //si se define un callerid 
        if(isset($arrProp["outcid"])){
            $query .="outcid=?,";
            $arrOpt[count($arrOpt)]=$arrProp["outcid"];
        }
      
        if(isset($arrProp["outcid_mode"])){
            $query .="outcid_mode=?,";
            $arrOpt[count($arrOpt)]=$arrProp["outcid_mode"];
        }
      
        //si se define un password
        if(isset($arrProp["routepass"])){
            $query .="routepass=?,";
            $arrOpt[count($arrOpt)]=$arrProp["routepass"];
        }

        
        if(isset($arrProp["mohsilence"])){
            $query .="mohsilence=?,";
            $arrOpt[count($arrOpt)]=$arrProp["mohsilence"];
        }

        if(isset($arrProp["time_group_id"])){
            $query .="time_group_id=?,";
            $arrOpt[count($arrOpt)]=$arrProp["time_group_id"];
        }

        if(!isset($arrProp["domain"]) || $arrProp["domain"]==""){
            $this->errMsg="It's necesary you create a new organization so you can create an Outbound to this organization";
        }else{
            $query .="organization_domain=?";
            $arrOpt[count($arrOpt)]=$arrProp["domain"];
        }
        //caller id options
                
        $query = $query." WHERE id=?";
            $arrOpt[count($arrOpt)]=$idOutbound;
        if($this->errMsg==""){
            $exito=$this->updateOutbound($query,$arrOpt,$arrProp);
        }else{
            return false;
        }

        if($exito==true){
            //si ahi dialpatterns se los procesa
                $resultDelete = $this->deleteDialPatterns($idOutbound);
                $resultDeleteTrunks = $this->deleteTrunks($idOutbound);
                if(($resultDelete==false)||($this->createDialPattern($arrDialPattern,$idOutbound)==false)||($resultDeleteTrunks==false)){
                    $this->errMsg="Outbound can't be updated.".$this->errMsg;
                    return false;
                }elseif($this->createTrunkPriority($arrTrunkPriority,$idOutbound,$arrProp["domain"])==false){
                    $this->errMsg="Outbound can't be updated .".$this->errMsg;
                    return false;
                }else
                    return true;
            //}
        }else
            return false;

    }

    private function updateOutbound($query,$arrOpt,$arrProp){
        if(!isset($arrProp["routename"]) || $arrProp["routename"]==""){
            $this->errMsg="Outbound can't be created. Outbound Name can't be empty";
            return false;
        }
        $result=$this->executeQuery($query,$arrOpt);
        
        
        if($result==false)
            $this->errMsg=$this->errMsg;
        return $result; 

        //validamos que no existe otros peers con el mismo nombre de truncal
        
    }

    private function createDialPattern($arrDialPattern,$outboundid)
    {
        $result=true;
        $seq = 0;
        if(is_array($arrDialPattern) && count($arrDialPattern)!=0){
            $temp=$arrDialPattern;
            $arrPattern= array();
            $query="INSERT INTO outbound_route_dialpattern (outbound_route_id,prepend,prefix,match_pattern,match_cid,seq) values (?,?,?,?,?,?)";
            foreach($arrDialPattern as $pattern){ 
                  $cid = getParameter("match_cid".$pattern);
                  $prepend = getParameter("prepend_digit".$pattern);
                  $prefix = getParameter("pattern_prefix".$pattern);
                  $pattern = getParameter("pattern_pass".$pattern);
                  $seq++;
                
                  $arrPattern=array($prepend,$prefix,$pattern,$cid,$outboundid);

                  //Verificamos que no se haya guardado un dial pattern igual
                  if($this-> checkDuplicateDialPattern($arrPattern)===true)
                  {
                if(isset($prepend)){
                      //validamos los campos
                      if(!preg_match("/^[[:digit:]]*$/",$prepend)){
                          $this->errMsg="Invalid dial pattern";
                          $result=false;
                          break;
                      }
                }else
                      $prepend="";
                
                if(isset($prefix)){
                      if(!preg_match("/^([XxZzNn[:digit:]]*(\[[0-9]+\-{1}[0-9]+\])*(\[[0-9]+\])*)+$/",$prefix)){
                          $this->errMsg="Invalid dial pattern";
                          $result=false;
                          break;
                      }
                }else
                      $prefix="";

                if(isset($pattern)){
                      if(!preg_match("/^([XxZzNn[:digit:]]*(\[[0-9]+\-{1}[0-9]+\])*(\[[0-9]+\])*)+$/",$pattern)){
                          $this->errMsg="Invalid dial pattern";
                          $result=false;
                          break;
                      }
                }else
                      $pattern="";
                  

                if($prepend!="" || $prefix!="" || $pattern!="")
                  $result=$this->executeQuery($query,array($outboundid,$prepend,$prefix,$pattern,$cid,$seq));
                  
                if($result==false)
                    break;
                }
            }
        }
        return $result;
    }

    //Trunk Priority
    private function createTrunkPriority($arrTrunkPriority,$outboundid,$domain)
    {
        $result=true;
        $seq = 0;
        
        /*$trunks=array();
        $query=$query="SELECT tr.trunkid from trunk as tr join trunk_organization as tor on tr.trunkid=tor.trunkid where organization_domain=?";
        $result=$this->_DB->fetchTable($query,true,array($domain));
        if($result===false){
            $this->errMsg=$this->_DB->errMsg;
            return false;
        }else{
            foreach($result as $value){
                $trunks[$value['trunkid']]=$value['trunkid'];
            }
            return $trunks;
        }*/
/*        
        if(is_array($arrTrunkPriority) && count($arrTrunkPriority)!=0){
            $temp=$arrTrunkPriority;
            $arrPattern= array();
            $query="INSERT INTO outbound_route_trunkpriority (outbound_route_id,trunk_id,seq) values (?,?,?)";
            foreach($arrTrunkPriority as $trunk){ 
                $trunk = getParameter("trunk".$trunk);
                $seq++;
                //if(in_array($trunk,$trunks)){
                    $result=$this->executeQuery($query, array($outboundid,$trunk,$seq));
                    if($result==false){
                        $this->errMsg="Error setting trunk sequence";
                        return false;
                    }
                //}
            }
        }else{
            $this->errMsg="At least one trunk must be selected";
            $result=false;
        }
        return $result;
    }



    private function checkDuplicateDialPattern($arr){
        $query="SELECT * from outbound_route_dialpattern WHERE prepend=? AND prefix=? AND match_pattern=? AND match_cid=? AND outbound_route_id=?";
        $result=$this->_DB->fetchTable($query,true,$arr);
        if(sizeof($result)==0)  
            return true;
        else
            return false;
    }

    function checkName($domain,$routename,$id_outbound=null){
          $where="";
          if(!isset($id_outbound))
              $id_outbound = "";
          
                  $arrParam=null;
          if(isset($domain)){
              if(!preg_match("/^(([[:alnum:]-]+)\.)+([[:alnum:]])+$/", $domain)){
                  $this->errMsg="Invalid domain format";
                  return false;
              }else{
                  $where="where organization_domain=? AND id<>? AND routename=? ";
                  $arrParam=array($domain,$id_outbound,$routename);
              }
          }
          
          $query="SELECT routename from outbound_route $where";
          
          $result=$this->_DB->fetchTable($query,true,$arrParam);
          if($result===false){
              $this->errMsg=$this->_DB->errMsg;
              return false;
          }else{
             if ($result==null)
                 return 0;
             else
                 return 1;
            }
    }

    private function deleteDialPatterns($outboundId){
        $queryD="DELETE from outbound_route_dialpattern where outbound_route_id=?";
        $result=$this->_DB->genQuery($queryD,array($outboundId));
        if($result==false){
            $this->errMsg=_tr("Error Deleting Outbound dialpatterns.").$this->_DB->errMsg;
            return false;
        }else
            return true;
              
    }
    
    private function deleteTrunks($outboundId){
        $queryD="DELETE from outbound_route_trunkpriority where outbound_route_id=?";
        $result=$this->_DB->genQuery($queryD,array($outboundId));
        if($result==false){
            $this->errMsg=_tr("Error Deleting Outbound trunkspriority.").$this->_DB->errMsg;
            return false;
        }else
            return true;
    }

    function deleteOutbound($outboundId){
        $resultDeleteTrunks = $this->deleteTrunks($outboundId);
        $resultDelete = $this->deleteDialPatterns($outboundId);
        if(($resultDelete==true)&&($resultDeleteTrunks==true)){
        $query="DELETE from outbound_route where id=?";
        if($this->executeQuery($query,array($outboundId))){
            return true;
        }else{
            $this->errMsg="Outbound can't be deleted.".$this->errMsg;
            return false;
        }
        }else{
            $this->errMsg="Outbound can't be deleted.".$this->errMsg;
            return false;
        }     
    }   
}
*/
?>
