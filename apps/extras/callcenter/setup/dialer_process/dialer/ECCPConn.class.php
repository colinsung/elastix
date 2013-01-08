<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Elastix version 1.2-2                                               |
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
  $Id: DialerProcess.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

class ECCPConn extends MultiplexConn
{
    public $DEBUG = FALSE;

    private $_log;
    private $_ami;
    private $_db;
    private $_tuberia;
    private $_listaReq = array();    // Lista de requerimientos pendientes
    private $_parser = NULL;        // Parser expat para separar los paquetes
    private $_iPosFinal = NULL;     // Posición de parser para el paquete parseado
    private $_sTipoDoc = NULL;      // Tipo de paquete. Sólo se acepta 'request'
    private $_bufferXML = '';       // Datos pendientes que no forman un paquete completo
    private $_iNestLevel = 0;       // Al llegar a cero, se tiene fin de paquete
    private $_eccpProcess = NULL;   // Proceso ECCPProcess que tiene rutinas de auditoría

    // Estado de la conexión
    private $_sUsuarioECCP  = NULL; // Nombre de usuario para cliente logoneado, o NULL si no logoneado
    private $_sAppCookie = NULL;    // Cadena a usar como cookie de la aplicación
    private $_bFinalizando = FALSE;

    // Si != NULL, eventos sólo se despachan si el agente coincide con este valor
    private $_sAgenteFiltrado = NULL;

    // Si VERDADERO, cliente está interesado en eventos de progreso de llamada
    private $_bProgresoLlamada = FALSE;

    function __construct($oMainLog, $tuberia)
    {
        $this->_log = $oMainLog;
        $this->_tuberia = $tuberia;
        $this->_resetParser();
    }

    function setAstConn($astConn)
    {
        $this->_ami = $astConn;
    }

    function setDbConn($dbConn)
    {
        $this->_db = $dbConn;
    }
    
    function setProcess($proc)
    {
    	$this->_eccpProcess = $proc;
    }

    // Datos a mandar a escribir apenas se inicia la conexión
    function procesarInicial() {}

    // Separar flujo de datos en paquetes, devuelve número de bytes de paquetes aceptados
    function parsearPaquetes($sDatos)
    {
        $this->parsearPaquetesXML($sDatos);
        return strlen($sDatos);
    }
    
    // Procesar cierre de la conexión
    function procesarCierre()
    {
        if (!is_null($this->_parser)) {
            xml_parser_free($this->_parser);
            $this->_parser = NULL;
        }
    }
    
    // Preguntar si hay paquetes pendientes de procesar
    function hayPaquetes() {
        return (count($this->_listaReq) > 0);
    }
    
    // Procesar un solo paquete de la cola de paquetes
    function procesarPaquete()
    {
        $request = array_shift($this->_listaReq);
        if (is_object($request)) {
            // Petición es un request, procesar
            if (count($request) != 1) {
                // La petición debe tener al menos un elemento hijo
                $response = $this->_generarRespuestaFallo(400, 'Bad request');
            } elseif (!isset($request['id'])) {
                // La petición debe tener un identificador
                $response = $this->_generarRespuestaFallo(400, 'Bad request');
            } else {
                if (is_null($this->_db)) {
                    // Todavía no se ha restaurado la conexión a la base de datos
                    $response = $this->_generarRespuestaFallo(500, 'Server error - database failure');
                } else {
                    if ($this->DEBUG) {
                    	$iTimestampRecibido = (double)$request['received'];
                        $proc_start = microtime(TRUE);
                        $this->_log->output('DEBUG: '.__METHOD__.': retraso '.
                            '(sec) hasta procesar: '.($proc_start - $iTimestampRecibido));
                    }

                    // Se procede normalmente...
                    $comando = NULL;
                    foreach ($request->children() as $c) $comando = $c;
                    $iTimestampInicio = microtime(TRUE);
                    $sRequerimiento = (string)$comando->getName();
                    $sMetodoImplementacion = "request_$sRequerimiento";
                    if (!method_exists($this, $sMetodoImplementacion)) {
                        $this->_log->output('ERR: (interno) no existe implementación para método: '.$sRequerimiento);
                        $response = $this->_generarRespuestaFallo(501, 'Not Implemented');
                    } else {
                        try {
                    	   $response = $this->$sMetodoImplementacion($comando);
                        } catch (PDOException $e) {
                        	$response = $this->_generarRespuestaFallo(503, 'Internal server error - database failure');
                            $this->_log->output('ERR: '.__METHOD__.
                                ': no se puede realizar operación de base de datos: '.
                                implode(' - ', $e->errorInfo));
                            $this->_log->output("ERR: traza de pila: \n".$e->getTraceAsString());
                            if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 2006) {
                                // Códigos correspondientes a pérdida de conexión de base de datos
                                $this->_log->output('WARN: '.__METHOD__.
                                    ': conexión a DB parece ser inválida, se cierra...');
                                $this->multiplexSrv->setDBConn(NULL);
                            }
                        }
                    }
                    
                    $iTimestampFinal = microtime(TRUE);
                    if ($this->DEBUG || (($iTimestampFinal - $iTimestampInicio) >= 1.0)) {
                        $this->_log->output('DEBUG: '.__METHOD__.': requerimiento '.
                            $comando->getName().' procesado luego de (sec): '.
                            ($iTimestampFinal - $iTimestampInicio));
                    }
                }
                $response->addAttribute('id', (string)$request['id']);
            }
            $s = $response->asXML();
            $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
            if ($this->_bFinalizando) $this->multiplexSrv->marcarCerrado($this->sKey);
        } else {
            // Marcador de error, se cierra la conexión
            $r = $this->_generarRespuestaFallo(400, 'Bad request');
            $s = $r->asXML();
            $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
            $this->multiplexSrv->marcarCerrado($this->sKey);
        }
    }
    
    // Función que construye una respuesta de petición incorrecta
    private function _generarRespuestaFallo($iCodigo, $sMensaje, $idPeticion = NULL)
    {
        $x = new SimpleXMLElement("<response />");
        if (!is_null($idPeticion))
            $x->addAttribute("id", $idPeticion);
        $this->_agregarRespuestaFallo($x, $iCodigo, $sMensaje);
        return $x;
    }
    
    // Agregar etiqueta failure a la respuesta indicada
    private function _agregarRespuestaFallo($x, $iCodigo, $sMensaje)
    {
        $failureTag = $x->addChild("failure");
        $failureTag->addChild("code", $iCodigo);
        $failureTag->addChild("message", str_replace('&', '&amp;', $sMensaje));
    } 
    
    // Procedimiento a llamar cuando se finaliza la conexión en cierre normal 
    // del programa.
    function finalizarConexion()
    {
        // Mandar a cerrar la conexión en sí
        $this->multiplexSrv->marcarCerrado($this->sKey);
        
        if (!is_null($this->_parser)) {
            xml_parser_free($this->_parser);
            $this->_parser = NULL;
        }
    }

    // Implementación de parser expat: inicio

    // Parsear y separar tantos paquetes XML como sean posibles
    private function parsearPaquetesXML($data)
    {
        $this->_bufferXML .= $data;
        $r = xml_parse($this->_parser, $data);
        while (!is_null($this->_iPosFinal)) {
            if ($this->_sTipoDoc == 'request') {
                $request = simplexml_load_string(substr($this->_bufferXML, 0, $this->_iPosFinal));
                $request->addAttribute('received', microtime(TRUE));
                $this->_listaReq[] = $request;
            } else {
                $this->_listaReq[] = array(
                    'errorcode'     =>  -1,
                    'errorstring'   =>  "Unrecognized packet type: {$this->_sTipoDoc}",
                    'errorline'     =>  xml_get_current_line_number($this->_parser),
                    'errorpos'      =>  xml_get_current_column_number($this->_parser),
                );
            }
            $this->_bufferXML = ltrim(substr($this->_bufferXML, $this->_iPosFinal));
            $this->_iPosFinal = NULL;
            $this->_resetParser();
            if ($this->_bufferXML != '')
                $r = xml_parse($this->_parser, $this->_bufferXML);
        }
        if (!$r) {
            $this->_listaReq[] = array(
                'errorcode'     =>  xml_get_error_code($this->_parser),
                'errorstring'   =>  xml_error_string(xml_get_error_code($this->_parser)),
                'errorline'     =>  xml_get_current_line_number($this->_parser),
                'errorpos'      =>  xml_get_current_column_number($this->_parser),
            );
        }
        return $r;
    }
    
    // Resetear el parseador, para iniciarlo, o luego de parsear un paquete
    private function _resetParser()
    {
        if (!is_null($this->_parser)) xml_parser_free($this->_parser);
        $this->_parser = xml_parser_create('UTF-8');
        xml_set_element_handler ($this->_parser,
            array($this, 'xmlStartHandler'),
            array($this, 'xmlEndHandler'));
        xml_parser_set_option($this->_parser, XML_OPTION_CASE_FOLDING, 0);
    }

    function xmlStartHandler($parser, $name, $attribs)
    {
        $this->_iNestLevel++;
    }

    function xmlEndHandler($parser, $name)
    {
        $this->_iNestLevel--;
        if ($this->_iNestLevel == 0) {
            $this->_iPosFinal = xml_get_current_byte_index($parser);
            $this->_sTipoDoc = $name;
        }
    }

    // Implementación de parser expat: final

    private function _parseAgent($sAgente)
    {
    	$regs = NULL;
        return preg_match('#^(Agent|SIP|IAX2)/(\d+)$#', $sAgente, $regs)
            ? array('type' => $regs[1], 'number' => $regs[2]) : NULL;
    }
    
    private function Request_getrequestlist($comando)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_getRequestListResponse = $xml_response->addChild('getrequestlist_response');
        
        $xml_requests = $xml_getRequestListResponse->addChild('requests');
        foreach (get_class_methods($this) as $sImplMetodo) {
        	if (substr($sImplMetodo, 0, 8) == 'Request_')
                $xml_requests->addChild('request', substr($sImplMetodo, 8));
        }
        return $xml_response;
    }

    private function Request_filterbyagent($comando)
    {
        // Verificar que agente está presente
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_filterbyagentResponse = $xml_response->addChild('filterbyagent_response');

        // El siguiente código asume formato Agent/9000
        if ($sAgente == 'any') {
            $sAgente = NULL;
        } elseif (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_filterbyagentResponse, 417, 'Invalid agent number');
            return $xml_response;
        }
        
        $this->_sAgenteFiltrado = $sAgente;
        $xml_filterbyagentResponse->addChild('success');

        return $xml_response;
    }

    /**
     * Procedimiento que implementa el login del cliente del protocolo. No se 
     * debe mandar ningún evento ni obedecer ningún otro requerimiento hasta que
     * se haya usado este comando para logonearse exitosamente
     * 
     * @param   object   $comando    Comando de login
     *      <login>
     *          <username>alice</username>
     *          <password>[md5hash]</password> <!-- md5hash es hash md5 de passwd -->
     *      </login>
     * 
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *      <login_response>
     *          <success /> | <failure>mensaje</failure>
     *      </login_response>
     */
    private function Request_login($comando)
    {
        // Verificar que usuario y clave están presentes
        if (!isset($comando->username) || !isset($comando->password)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        
        $xml_response = new SimpleXMLElement('<response />');
        $xml_loginResponse = $xml_response->addChild('login_response');

        /* FIXME: No me queda claro de qué manera es más seguro mandar el hash
         * del password, que el password en texto plano, en una conexión sin
         * encriptar, ya que en ambos casos se puede recoger con un sniffer.
         * Por ahora se acepta el password con o sin hash. */
        /* TODO: se puede almacenar cuál agente(s) está autorizado a atender en 
         * la tabla eccp_authorized_clients */
        $sPeticionSQL = 
            'SELECT COUNT(*) AS N FROM eccp_authorized_clients '.
            'WHERE username = ? AND (md5_password = ? OR md5_password = md5(?))';
        $paramSQL = array($comando->username, $comando->password, $comando->password);
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute($paramSQL);
        $tupla = $recordset->fetch(); $recordset->closeCursor();
        if ($tupla['N'] > 0) {
            // Usuario autorizado
            $this->_sUsuarioECCP = $comando->username;
            $xml_status = $xml_loginResponse->addChild('success');
            
            // Generar una cadena de hash para cookie de aplicación
            $sAppCookie = md5(posix_getpid().time().mt_rand());
            $xml_loginResponse->addChild('app_cookie', $sAppCookie);
            $this->_sAppCookie = $sAppCookie;
        } else {
            // Usuario no existe, o clave incorrecta
            $this->_agregarRespuestaFallo($xml_loginResponse, 401, 'Invalid username or password');
        }
        return $xml_response;
    }
    
    /**
     * Procedimiento que implementa el logout del cliente del protocolo. Luego 
     * de este requerimiento, se espera que se cierre la conexión.
     * 
     * @param   object   $comando    Comando de logout
     *      <logout />
     * 
     * @return  object  Respuesta codificada como un SimpleXMLObject  
     *      <logout_response />
     */
    private function Request_logout($comando)
    {
        $this->_sUsuarioECCP = NULL;
        $this->_sAppCookie = NULL;
        $xml_response = new SimpleXMLElement('<response />');
        $xml_loginResponse = $xml_response->addChild('logout_response');
        $xml_status = $xml_loginResponse->addChild('success');
        $this->_bFinalizando = TRUE;
        return $xml_response;
    }

    // Revisar si el comando indicado tiene un hash válido. El comando debe de
    // tener los campos agent_number y agent_hash
    private function _hashValidoAgenteECCP($comando)
    {
        if (!isset($comando->agent_number) || !isset($comando->agent_hash))
            return FALSE;
        $sAgente = (string)$comando->agent_number;
        $sHashCliente = (string)$comando->agent_hash;

        $recordset = $this->_db->prepare(
            'SELECT number, eccp_password FROM agent '.
            "WHERE estatus = 'A' AND CONCAT(type,'/',number) = ?");
        $recordset->execute(array($sAgente));
        $tuplaAgente = $recordset->fetch(); $recordset->closeCursor();
        if (!$tuplaAgente) {
            // Agente no se ha encontrado en la base de datos
            return FALSE;
        }
        $sClaveECCPAgente = $tuplaAgente['eccp_password'];
        
        // Para pruebas, se acepta a agente sin password
        if (is_null($sClaveECCPAgente)) return TRUE;
        
        // Calcular el hash que debió haber enviado el cliente
        $sHashEsperado = md5($this->_sAppCookie.$sAgente.$sClaveECCPAgente);
        return ($sHashEsperado == $sHashCliente);
    }

    private function Request_getqueuescript($comando)
    {
        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que queue está presente
        if (!isset($comando->queue)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $queue = (int)$comando->queue;
        
        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetQueueScriptResponse = $xml_response->addChild('getqueuescript_response');
        
        // Leer la información del script de la cola. El ORDER BY estatus hace
        // que se devuelva A y luego I.
        $recordset = $this->_db->prepare(
            'SELECT script FROM queue_call_entry '.
            'WHERE queue = ? ORDER BY estatus LIMIT 0,1');
        $recordset->execute(array($queue));
        $tupla = $recordset->fetch(); $recordset->closeCursor(); 
        if (!$tupla) {
            $this->_agregarRespuestaFallo($xml_GetQueueScriptResponse, 404, 'Queue not found in incoming queues');
            return $xml_response;
        }
        $xml_GetQueueScriptResponse->addChild('script', str_replace('&', '&amp;', $tupla['script']));        
        return $xml_response;
    }

    private function Request_getcampaignlist($comando)
    {
        // Tipo de campaña 
        $sTipoCampania = NULL;
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }
        $listaTiposConocidos = array('incoming', 'outgoing');
        if (!is_null($sTipoCampania) && !in_array($sTipoCampania, $listaTiposConocidos))
            return $this->_generarRespuestaFallo(400, 'Bad request - invalid campaign type');
        if (!is_null($sTipoCampania))
            $listaTipos = array($sTipoCampania);
        else $listaTipos = $listaTiposConocidos;

        // Filtro por nombre
        $sNombreContiene = NULL;
        if (isset($comando->filtername)) {
            $sNombreContiene = (string)$comando->filtername;
        }
        
        // Filtro por status
        $sEstado = NULL;
        if (isset($comando->status)) {
            $sEstado = (string)$comando->status;
            $listaEstadosConocidos = array(
                'active'    =>  'A',
                'inactive'  =>  'I',
                'finished'  =>  'T');
            if (!in_array($sEstado, array_keys($listaEstadosConocidos)))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid status');
            $sEstado = $listaEstadosConocidos[$sEstado];
        }
        
        // Fechas de inicio y fin
        $sFechaInicio = $sFechaFin = NULL;
        if (isset($comando->datetime_start)) {
            $sFechaInicio = (string)$comando->datetime_start;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaInicio))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid start date');
        }
        if (isset($comando->datetime_end)) {
            $sFechaFin = (string)$comando->datetime_end;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaFin))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid end date');
        }
        if (!is_null($sFechaInicio) && !is_null($sFechaFin) && $sFechaFin < $sFechaInicio) {
            $t = $sFechaInicio;
            $sFechaInicio = $sFechaFin;
            $sFechaFin = $t;
        }
        
        // Offset y límite
        $iOffset = NULL; $iLimite = NULL;
        if (isset($comando->limit)) {
            $iLimite = (int)$comando->limit;
            $iOffset = 0;
        }
        if (isset($comando->offset)) $iOffset = (int)$comando->offset;
        if (!is_null($iOffset) && is_null($iLimite))
            return $this->_generarRespuestaFallo(400, 'Bad request - offset without limit');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCampaignListResponse = $xml_response->addChild('getcampaignlist_response');

        $recordset = array();
        $listaSQL = array();
        $paramSQL = array();

        foreach ($listaTipos as $sTipo) {
            switch ($sTipo) {
            case 'incoming':
                $sPeticionSQL = "SELECT 'incoming' AS campaign_type, id, name, estatus AS status FROM campaign_entry";
                break;
            case 'outgoing':
                $sPeticionSQL = "SELECT 'outgoing' AS campaign_type, id, name, estatus AS status FROM campaign";
                break;
            }
            
            $listaWhere = array();
            if (!is_null($sNombreContiene)) {
                $listaWhere[] = 'name LIKE ?';
                $paramSQL[] = '%'.$sNombreContiene.'%';
            }
            if (!is_null($sEstado)) {
                $listaWhere[] = 'estatus = ?';
                $paramSQL[] = $sEstado;
            }
            if (!is_null($sFechaInicio)) {
                $listaWhere[] = 'datetime_init >= ?';
                $paramSQL[] = $sFechaInicio;
            }
            if (!is_null($sFechaFin)) {
                $listaWhere[] = 'datetime_init < ?';
                $paramSQL[] = $sFechaFin;
            }
            
            if (count($listaWhere) > 0) {
                $sPeticionSQL .= ' WHERE '.implode(' AND ', $listaWhere);
            }
            
            $listaSQL[] = $sPeticionSQL;
        }
        
        // Preparar UNION SQL
        if (count($listaSQL) > 0)
            $sPeticionSQL = '('.implode(') UNION (', $listaSQL).')';
        else $sPeticionSQL = $listaSQL[0];

        $sPeticionSQL .= ' ORDER BY campaign_type, id';
        if (!is_null($iLimite)) {
            $sPeticionSQL .= ' LIMIT ? OFFSET ?';
            $paramSQL[] = $iLimite;
            $paramSQL[] = $iOffset;
        }
        
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute($paramSQL);

        $descEstados = array(
            'A' =>  'active',
            'I' =>  'inactive',
            'T' =>  'finished',
        );

        $xml_campaigns = $xml_GetCampaignListResponse->addChild('campaigns');
        foreach ($recordset as $tupla) {
            $xml_campaign = $xml_campaigns->addChild('campaign');
            $xml_campaign->addChild('id', $tupla['id']);
            $xml_campaign->addChild('type', $tupla['campaign_type']);
            $xml_campaign->addChild('name', str_replace('&', '&amp;', $tupla['name']));
            $xml_campaign->addChild('status', $descEstados[$tupla['status']]);
        }

        return $xml_response;
    }

    private function Request_getcampaignqueuewait($comando)
    {
        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que id y tipo está presente
        if (!isset($comando->campaign_id)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        if (!isset($comando->campaign_type)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idCampania = (int)$comando->campaign_id;
        $sTipoCampania = (string)$comando->campaign_type;

        // Elegir SQL a partir del tipo de campaña requerida
        if ($sTipoCampania == 'incoming') {
            $sqlLlamadasExito = 'SELECT COUNT(*) AS N, duration_wait FROM call_entry WHERE id_campaign = ? AND (status = "activa" OR status = "terminada") GROUP BY duration_wait';
            $sqlLlamadasAbandonadas = 'SELECT COUNT(*) AS N FROM call_entry WHERE id_campaign = ? AND status = "abandonada"';
        } elseif ($sTipoCampania == 'outgoing') {
            $sqlLlamadasExito = 'SELECT COUNT(*) AS N, duration_wait FROM calls WHERE id_campaign = ? AND status = "Success" GROUP BY duration_wait';
            $sqlLlamadasAbandonadas = 'SELECT COUNT(*) AS N FROM calls WHERE id_campaign = ? AND status = "Abandoned"';
        } else {
            return $this->_generarRespuestaFallo(400, 'Bad request');
        }

        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCampaignQueueWaitResponse = $xml_response->addChild('getcampaignqueuewait_response');

        $recordset = $this->_db->prepare($sqlLlamadasExito);
        $recordset->execute(array($idCampania));

        // División del histograma: tamaño de intervalos y límite máximo
        $iValorIntervalo = 5; $iMaxValor = 30;
        $histograma = array();
        for ($i = 0; $i <= $iMaxValor; $i += $iValorIntervalo) {
            $histograma[$i / $iValorIntervalo] = 0;
        }
        foreach ($recordset as $tupla) {
            $iPosHistograma = ($tupla['duration_wait'] >= $iMaxValor)
                ? count($histograma) - 1
                : (int)($tupla['duration_wait'] / $iValorIntervalo);
            $histograma[$iPosHistograma] += $tupla['N'];
        }

        $recordset = $this->_db->prepare($sqlLlamadasAbandonadas);
        $recordset->execute(array($idCampania));
        $tuplaAbandonadas = $recordset->fetch(); $recordset->closeCursor();

        // Construcción de la respuesta
        $xml_histograma = $xml_GetCampaignQueueWaitResponse->addChild('histogram');
        foreach ($histograma as $iPosHistograma => $iCuentaHistograma) {
            $iValorInferior = $iPosHistograma * $iValorIntervalo;
            $iValorSuperior = $iValorInferior + $iValorIntervalo - 1;
            $xml_intervalo = $xml_histograma->addChild('interval');
            $xml_intervalo->addChild('lower', $iValorInferior);
            if ($iPosHistograma != count($histograma) - 1)
                $xml_intervalo->addChild('upper', $iValorSuperior);
            $xml_intervalo->addChild('count', $iCuentaHistograma);
        }
        $xml_GetCampaignQueueWaitResponse->addChild('abandoned', $tuplaAbandonadas['N']);

        return $xml_response;
    }

    /**
     * Procedimiento que implementa la lectura de la información estática de 
     * una campaña entrante o saliente. Por información estática se entiende la
     * información que no cambia a medida que se progresa con las llamadas
     * asociadas a la campaña.
     * 
     * @param   object  $comando    Comando
     *      <getcampaigninfo>
     *          <campaign_type>outgoing|incoming</campaign_type> <!-- Opcional, por omisión es outgoing -->
     *          <campaign_id>123</campaign_id>
     *      </getcampaigninfo>
     * 
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *      <getcampaigninfo_response>
     *          <name>Nombre de la campaña</name>
     *          <type>incoming|outgoing</type>
     *          <startdate>yyyy-mm-dd</startdate>
     *          <enddate>yyyy-mm-dd</enddate>
     *          <working_time_starttime>hh:mm:ss</working_time_starttime>
     *          <working_time_endtime>hh:mm:ss</working_time_endtime>
     *          <queue>8000</queue>
     *          <retries>5</retries>                <!-- Sólo saliente -->
     *          <trunk>SIP/saliente</trunk>         <!-- Sólo saliente. Si no presente, se asume Local/xxx@from-internal -->
     *          <context>from-internal</context>    <!-- Sólo saliente -->
     *          <maxchan>32</maxchan>               <!-- Sólo saliente -->
     *          <status>active|inactive|complete</status>
     *          <script>Texto a usar como script de la campaña</script>
     *          <form id="2">...</form>
     *          <form id="3">...</form>
     *      </getcampaigninfo_response> 
     */
    private function Request_getcampaigninfo($comando)
    {
        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que id y tipo está presente
        if (!isset($comando->campaign_id)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idCampania = (int)$comando->campaign_id;
        $sTipoCampania = 'outgoing';
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }

        switch ($sTipoCampania) {
        case 'incoming':
            return $this->_leerInfoCampaniaXML_incoming($idCampania);
        case 'outgoing':
            return $this->_leerInfoCampaniaXML_outgoing($idCampania);
        default:
            return $this->_generarRespuestaFallo(400, 'Bad request');
        }
    }
    
    private function _leerInfoCampaniaXML_outgoing($idCampania)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCampaignInfoResponse = $xml_response->addChild('getcampaigninfo_response');

        // Leer la información de la campaña saliente
        $sPeticionSQL = <<<LEER_CAMPANIA
SELECT name, 'outgoing' AS type, datetime_init AS startdate, datetime_end AS enddate,
    daytime_init AS working_time_starttime, daytime_end AS working_time_endtime, 
    queue, retries, trunk, context, max_canales AS maxchan, estatus AS status,
    script, urltemplate, opentype AS urlopentype
FROM campaign 
LEFT JOIN campaign_external_url
    ON campaign.id_url = campaign_external_url.id AND campaign_external_url.active = 1 
WHERE campaign.id = ?
LEER_CAMPANIA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania));
        $tuplaCampania = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
        if (!$tuplaCampania) {
            $this->_agregarRespuestaFallo($xml_GetCampaignInfoResponse, 404, 'Campaign not found');
            return $xml_response;
        }

        // Leer la lista de formularios asociados a esta campaña
        $recordset = $this->_db->prepare('SELECT DISTINCT id_form FROM campaign_form WHERE id_campaign = ?');
        $recordset->execute(array($idCampania));
        $idxForm = $recordset->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Leer los campos asociados a cada formulario
        $listaForm = $this->_leerCamposFormulario($idxForm);
        if (is_null($listaForm)) {
            $this->_agregarRespuestaFallo($xml_GetCampaignInfoResponse, 500, 'Cannot read campaign info (formfields)');
            return $xml_response;
        }
        $listaNombresForm = $this->_leerInfoFormulario($idxForm);
        if (is_null($listaNombresForm)) {
            $this->_agregarRespuestaFallo($xml_GetCampaignInfoResponse, 500, 'Cannot read campaign info (formnames)');
            return $xml_response;
        }

        // Construir la respuesta con la información del campo
        $descEstados = array(
            'A' =>  'active',
            'I' =>  'inactive',
            'T' =>  'finished',
        );
        foreach ($tuplaCampania as $sKey => $sValor) {
            switch ($sKey) {
            case 'script':
                /* El control de edición en la creación/modificación del script
                 * manda a guardar texto con entidades de HTML a la base de 
                 * datos. Para compatibilidad con campañas antiguas, se deshace
                 * la codificación de HTML aquí. */
                $sValor = html_entity_decode($sValor, ENT_COMPAT, 'UTF-8');
                $xml_GetCampaignInfoResponse->addChild($sKey, str_replace('&', '&amp;', $sValor));
                break;
            case 'status':
                $sValor = $descEstados[$sValor];
                $xml_GetCampaignInfoResponse->addChild($sKey, str_replace('&', '&amp;', $sValor));
                break;
            case 'trunk':
                // Pasar al caso default si el valor no es nulo
                if (is_null($sValor)) break;
            default:
                $xml_GetCampaignInfoResponse->addChild($sKey, str_replace('&', '&amp;', $sValor));
                break;
            }
        }

        // Construir la información de los formularios
        $xml_Forms = $xml_GetCampaignInfoResponse->addChild('forms');
        foreach ($listaForm as $idForm => $listaCampos) {
            $this->_agregarCamposFormulario($xml_Forms, $idForm, $listaCampos, $listaNombresForm[$idForm]);
        }

        return $xml_response;
    }
    
    private function _leerInfoCampaniaXML_incoming($idCampania)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCampaignInfoResponse = $xml_response->addChild('getcampaigninfo_response');

        // Leer la información de la campaña entrante
        $sPeticionSQL = <<<LEER_CAMPANIA
SELECT name, 'incoming' AS type, datetime_init AS startdate, datetime_end AS enddate,
    daytime_init AS working_time_starttime, daytime_end AS working_time_endtime,
    queue, campaign_entry.estatus AS status, campaign_entry.script, id_form, 
    urltemplate, opentype AS urlopentype
FROM (campaign_entry, queue_call_entry)
LEFT JOIN campaign_external_url
    ON campaign_entry.id_url = campaign_external_url.id AND campaign_external_url.active = 1 
WHERE campaign_entry.id = ? AND campaign_entry.id_queue_call_entry = queue_call_entry.id
LEER_CAMPANIA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania));
        $tuplaCampania = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
        if (!$tuplaCampania) {
            $this->_agregarRespuestaFallo($xml_GetCampaignInfoResponse, 404, 'Campaign not found');
            return $xml_response;
        }

        // Leer la lista de formularios asociados a esta campaña
        $recordset = $this->_db->prepare('SELECT DISTINCT id_form FROM campaign_form_entry WHERE id_campaign = ?');
        $recordset->execute(array($idCampania));
        $idxForm = $recordset->fetchAll(PDO::FETCH_COLUMN, 0);

        if (!is_null($tuplaCampania['id_form']) && !in_array($tuplaCampania['id_form'], $idxForm))
            $idxForm[] = $tuplaCampania['id_form'];
        unset($tuplaCampania['id_form']);
        
        // Leer los campos asociados a cada formulario
        $listaForm = $this->_leerCamposFormulario($idxForm);
        if (is_null($listaForm)) {
            $this->_agregarRespuestaFallo($xml_GetCampaignInfoResponse, 500, 'Cannot read campaign info (formfields)');
            return $xml_response;
        }
        $listaNombresForm = $this->_leerInfoFormulario($idxForm);
        if (is_null($listaNombresForm)) {
            $this->_agregarRespuestaFallo($xml_GetCampaignInfoResponse, 500, 'Cannot read campaign info (formnames)');
            return $xml_response;
        }

        // Construir la respuesta con la información del campo
        $descEstados = array(
            'A' =>  'active',
            'I' =>  'inactive',
            'T' =>  'finished',
        );
        foreach ($tuplaCampania as $sKey => $sValor) {
            switch ($sKey) {
            case 'script':
                /* El control de edición en la creación/modificación del script
                 * manda a guardar texto con entidades de HTML a la base de 
                 * datos. Para compatibilidad con campañas antiguas, se deshace
                 * la codificación de HTML aquí. */
                $sValor = html_entity_decode($sValor, ENT_COMPAT, 'UTF-8');
                $xml_GetCampaignInfoResponse->addChild($sKey, str_replace('&', '&amp;', $sValor));
                break;
            case 'status':
                $sValor = $descEstados[$sValor];
                // Cae al siguiente caso
            default:
                $xml_GetCampaignInfoResponse->addChild($sKey, str_replace('&', '&amp;', $sValor));
                break;
            }
        }

        // Construir la información de los formularios
        $xml_Forms = $xml_GetCampaignInfoResponse->addChild('forms');
        foreach ($listaForm as $idForm => $listaCampos) {
            $this->_agregarCamposFormulario($xml_Forms, $idForm, $listaCampos, $listaNombresForm[$idForm]);
        }

        return $xml_response;
    }
    
    private function _leerInfoFormulario($idxForm)
    {
        $listaForm = array();
        foreach ($idxForm as $idForm) {
            $recordset = $this->_db->prepare(
                'SELECT id, nombre, descripcion, estatus FROM form WHERE id = ?');
            $recordset->execute(array($idForm));
            $r = $recordset->fetch(); $recordset->closeCursor();
            if ($r) {
                $listaForm[$idForm] = array(
                    'name'          =>  $r['nombre'],
                    'description'   =>  $r['descripcion'],
                    'status'        =>  $r['estatus'],
                );
            }
        }
        return $listaForm;
    }
    
    private function _leerCamposFormulario($idxForm)
    {
        $listaForm = array();
        foreach ($idxForm as $idForm) {
            $recordset = $this->_db->prepare(
                'SELECT id, etiqueta AS label, value, tipo AS type, orden AS `order` '.
                'FROM form_field WHERE id_form = ? ORDER BY `order`');
            $recordset->execute(array($idForm));
        	$r = $recordset->fetchAll(PDO::FETCH_ASSOC);
            if (count($r) > 0) {
                $listaForm[$idForm] = array();
                foreach ($r as $tuplaCampo)
                    $listaForm[$idForm][$tuplaCampo['id']] = $tuplaCampo;
            }
        }
        return $listaForm;
    }
    
    private function _agregarCamposFormulario(&$xml_GetCampaignInfoResponse, $idForm, &$listaCampos, &$nombresForm)
    {
        $xml_Form = $xml_GetCampaignInfoResponse->addChild('form');
        $xml_Form->addAttribute('id', $idForm);
        // Rodeo para bug PHP https://bugs.php.net/bug.php?id=41175
        if ($nombresForm['name'] != '')
            $xml_Form->addAttribute('name', $nombresForm['name']);
        if ($nombresForm['description'] != '')
            $xml_Form->addAttribute('description', $nombresForm['description']);
        $xml_Form->addAttribute('status', $nombresForm['status']);
        foreach ($listaCampos as $tuplaCampo) {
            $xml_Field = $xml_Form->addChild('field');
            $xml_Field->addAttribute('order', $tuplaCampo['order']);
            $xml_Field->addAttribute('id', $tuplaCampo['id']);
            $xml_Field->addChild('label', str_replace('&', '&amp;', $tuplaCampo['label']));
            $xml_Field->addChild('type', str_replace('&', '&amp;', $tuplaCampo['type']));
            
            // TODO: permitir especificar longitud de la entrada
            if (!in_array($tuplaCampo['type'], array('LABEL', 'DATE'))) 
                $xml_Field->addChild('maxsize', 250);
            
            if ($tuplaCampo['type'] == 'LIST') {
                // OJO: PRIMERA FORMA ANORMAL!!!
                // La implementación actual del código de formulario
                // agrega una coma de más al final de la lista
                if (strlen($tuplaCampo['value']) > 0 && 
                    substr($tuplaCampo['value'], strlen($tuplaCampo['value']) - 1, 1) == ',') {
                    $tuplaCampo['value'] = substr($tuplaCampo['value'], 0, strlen($tuplaCampo['value']) - 1);
                }
                $xml_Values = $xml_Field->addChild('options');
                foreach (explode(',', $tuplaCampo['value']) as $sValor) {
                    $xml_Values->addChild('value', str_replace('&', '&amp;', $sValor));
                }
            } else {
                // Usar el valor 'value' como valor por omisión. 
                // TODO: (2011-02-02) soporte de formulario para valor por 
                // omisión todavía no está implementado en agent_console o en 
                // definición de formulario en interfaz web
                $sDefVal = trim($tuplaCampo['value']);
                if ($sDefVal != '') 
                    $xml_Field->addChild('default_value', str_replace('&', '&amp;', $sDefVal));
            }
        }
    }

    private function Request_getcallinfo($comando)
    {
        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Si no hay un tipo de campaña, se asume saliente
        $sTipoCampania = 'outgoing';
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }
        if (!in_array($sTipoCampania, array('incoming', 'outgoing')))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        // El ID de campaña es opcional para campañas entrantes
        if (!isset($comando->campaign_id) && $sTipoCampania == 'outgoing') 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idCampania = isset($comando->campaign_id) ? (int)$comando->campaign_id : NULL; 

        // Verificar que id de llamada está presente
        if (!isset($comando->call_id)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idLlamada = (int)$comando->call_id;

        // Ejecutar la llamada y verificar la respuesta...
        $infoLlamada = $this->_eccpProcess->leerInfoLlamada($sTipoCampania, $idCampania, $idLlamada);

        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCallInfoResponse = $xml_response->addChild('getcallinfo_response');
        if (is_null($infoLlamada)) {
            $this->_agregarRespuestaFallo($xml_GetCallInfoResponse, 500, 'Cannot read call info');
            return $xml_response;
        }
        if (count($infoLlamada) <= 0) {
            $this->_agregarRespuestaFallo($xml_GetCallInfoResponse, 404, 'Call not found');
            return $xml_response;
        }

        // Armar la respuesta XML
        $this->_construirRespuestaCallInfo($infoLlamada, $xml_GetCallInfoResponse);
        return $xml_response;
    }
    
    // Compartido entre getcallinfo y evento agentlinked
    private function _construirRespuestaCallInfo($infoLlamada, $xml_GetCallInfoResponse)
    {
        foreach ($infoLlamada as $sKey => $valor) {
            switch ($sKey) {
            case 'call_attributes':
                $xml_callAttrlist = $xml_GetCallInfoResponse->addChild($sKey);
                foreach ($valor as $tuplaAttr) {
                    $xml_callAttr = $xml_callAttrlist->addChild('attribute');
                    $xml_callAttr->addChild('label', str_replace('&', '&amp;', $tuplaAttr['label'])); 
                    $xml_callAttr->addChild('value', str_replace('&', '&amp;', $tuplaAttr['value']));
                    $xml_callAttr->addChild('order', str_replace('&', '&amp;', $tuplaAttr['order']));
                }
                break;
            case 'matching_contacts':
                $xml_contacts = $xml_GetCallInfoResponse->addChild($sKey);
                foreach ($valor as $id_contact => $tuplaContact) {
                    $xml_callAttrlist = $xml_contacts->addChild('contact');
                    $xml_callAttrlist->addAttribute('id', $id_contact);
                    foreach ($tuplaContact as $tuplaAttr) {
                        $xml_callAttr = $xml_callAttrlist->addChild('attribute');
                        $xml_callAttr->addChild('label', str_replace('&', '&amp;', $tuplaAttr['label'])); 
                        $xml_callAttr->addChild('value', str_replace('&', '&amp;', $tuplaAttr['value']));
                        $xml_callAttr->addChild('order', str_replace('&', '&amp;', $tuplaAttr['order']));
                    }
                }
                break;
            case 'call_survey':
                $xml_callFormlist = $xml_GetCallInfoResponse->addChild($sKey);
                foreach ($valor as $id_form => $valoresForm) {
                    $xml_callForm = $xml_callFormlist->addChild('form');
                    $xml_callForm->addAttribute('id', $id_form);
                    foreach ($valoresForm as $tuplaValor) {
                        $xml_callFormField = $xml_callForm->addChild('field');
                        $xml_callFormField->addAttribute('id', $tuplaValor['id']);
                        $xml_callFormField->addChild('label', str_replace('&', '&amp;', $tuplaValor['label']));
                        $xml_callFormField->addChild('value', str_replace('&', '&amp;', $tuplaValor['value']));
                    }
                }
                break;
            default:
                if (!is_null($valor)) $xml_GetCallInfoResponse->addChild($sKey, str_replace('&', '&amp;', $valor));
                break;
            }
        }
    }

    private function _leerAgenteLlamada($sTipoCampania, $idLlamada)
    {
        switch ($sTipoCampania) {
        case 'incoming':
            $sDescCampania = 'entrante';
            $sPeticionSQL = 
                'SELECT CONCAT(agent.type,"/",agent.number) AS agentchannel FROM call_entry, agent '.
                'WHERE call_entry.id_agent = agent.id AND call_entry.id = ?';
            break;
        case 'outgoing':
            $sDescCampania = 'saliente';
            $sPeticionSQL = 
                'SELECT CONCAT(agent.type,"/",agent.number) AS agentchannel FROM calls, agent '.
                'WHERE calls.id_agent = agent.id AND calls.id = ?'; 
            break;
        default:
            return NULL;
        }
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idLlamada));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        return $tupla ? $tupla['agentchannel'] : NULL;
    }

    private function Request_setcontact($comando)
    {
        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que id de llamada está presente
        if (!isset($comando->call_id)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idLlamada = (int)$comando->call_id;

        // Verificar que id de contacto está presente
        if (!isset($comando->contact_id)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idContacto = (int)$comando->contact_id;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_setContactResponse = $xml_response->addChild('setcontact_response');

        $bExito = TRUE;

        // Verificar que el agente está autorizado a realizar operación
        if ($bExito) {
            if (!$this->_hashValidoAgenteECCP($comando)) {
                $this->_agregarRespuestaFallo($xml_setContactResponse, 401, 'Unauthorized agent');
                $bExito = FALSE;
            }
        }

        // Verificar que existe realmente la llamada entrante
        if ($bExito) {
            $recordset = $this->_db->prepare(
                'SELECT COUNT(*) AS N FROM call_entry WHERE id = ?');
            $recordset->execute(array($idLlamada));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
            if ($tupla['N'] < 1) {
                $this->_agregarRespuestaFallo($xml_setContactResponse, 404, 'Call ID not found');
                $bExito = FALSE;
            }
        }
        
        // Verificar que el agente declarado realmente atendió esta llamada
        if ($bExito) {
            $sAgenteLlamada = $this->_leerAgenteLlamada('incoming', $idLlamada);
            if (is_null($sAgenteLlamada) || $sAgenteLlamada != (string)$comando->agent_number) {
                $this->_agregarRespuestaFallo($xml_setContactResponse, 401, 'Unauthorized agent');
                $bExito = FALSE;
            }
        }

        // Verificar que existe realmente el contacto indicado
        if ($bExito) {
            $recordset = $this->_db->prepare(
                'SELECT COUNT(*) AS N FROM contact WHERE id = ?');
            $recordset->execute(array($idContacto));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
            if ($tupla['N'] < 1) {
                $this->_agregarRespuestaFallo($xml_setContactResponse, 404, 'Contact ID not found');
                $bExito = FALSE;
            }
        }
        
        if ($bExito) {
            $sth = $this->_db->prepare('UPDATE call_entry SET id_contact = ? WHERE id = ?');
            $sth->execute(array($idContacto, $idLlamada));
        }

        if ($bExito) {
            $xml_setContactResponse->addChild('success');
        }

        return $xml_response;
    }

    /*    
    private function Request_dial($comando)
    {
        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');
        return $this->_generarRespuestaFallo(501, 'Not Implemented');
    }
    */
    
    private function Request_saveformdata($comando)
    {
        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Si no hay un tipo de campaña, se asume saliente
        $sTipoCampania = 'outgoing';
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }
        if (!in_array($sTipoCampania, array('incoming', 'outgoing')))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        // Verificar que id de llamada está presente
        if (!isset($comando->call_id)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idLlamada = (int)$comando->call_id;

        // Verificar que elemento forms está presente
        if (!isset($comando->forms)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $infoDatos = array();
        foreach ($comando->forms->form as $xml_form) {
            $idForm = (int)$xml_form['id'];
            
            // No se permiten IDs duplicados de formulario
            if (isset($infoDatos[$idForm]))
                return $this->_generarRespuestaFallo(400, 'Bad request');
            
            $infoDatos[$idForm] = array();
            foreach ($xml_form->field as $xml_field) {
                $idField = (int)$xml_field['id'];
                $infoDatos[$idForm][$idField] = (string)$xml_field;
            }
        }

        $xml_response = new SimpleXMLElement('<response />');
        $xml_saveFormDataResponse = $xml_response->addChild('saveformdata_response');

        // Verificar que el agente está autorizado a realizar operación
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        // Verificar que el agente declarado realmente atendió esta llamada
        $sAgenteLlamada = $this->_leerAgenteLlamada($sTipoCampania, $idLlamada);
        if (is_null($sAgenteLlamada) || $sAgenteLlamada != (string)$comando->agent_number) {
            $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        // Leer la información del formulario, para validación
        $infoFormulario = $this->_leerCamposFormulario(array_keys($infoDatos));
        if (is_null($infoFormulario)) {
            $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 500, 'Cannot read form information');
        } else {
            $listaSQL = array();
            $recordset = $this->_db->prepare(($sTipoCampania == 'incoming')
                ? 'SELECT COUNT(*) FROM form_data_recolected_entry WHERE id_call_entry = ? AND id_form_field = ?'
                : 'SELECT COUNT(*) FROM form_data_recolected WHERE id_calls = ? AND id_form_field = ?');
            $sth_insert = $this->_db->prepare(($sTipoCampania == 'incoming') 
                ? 'INSERT INTO form_data_recolected_entry (value, id_call_entry, id_form_field) VALUES (?, ?, ?)'
                : 'INSERT INTO form_data_recolected (value, id_calls, id_form_field) VALUES (?, ?, ?)');
            $sth_update = $this->_db->prepare(($sTipoCampania == 'incoming') 
                ? 'UPDATE form_data_recolected_entry SET value = ? WHERE id_call_entry = ? AND id_form_field = ?'
                : 'UPDATE form_data_recolected SET value = ? WHERE id_calls = ? AND id_form_field = ?');
            
            /* Validación básica de los valores a guardar, combinada con 
             * generación de las sentencias SQL para almacenar */
            $bDatosValidos = TRUE;
            foreach ($infoDatos as $idForm => $infoDatosForm) {
                foreach ($infoDatosForm as $idField => $sValor) {
                    if (!isset($infoFormulario[$idForm])) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 404, 'Form ID not found: '.$idForm);
                    } elseif (!isset($infoFormulario[$idForm][$idField])) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 404, 'Field ID not found in form: '.$idForm.' - '.$idField);
                    }
                    if (!$bDatosValidos) break;

                    $infoCampo = $infoFormulario[$idForm][$idField];
                    if ($infoCampo['type'] == 'LABEL') continue; 
                    
                    // TODO: extraer máxima longitud de base de datos
                    if (strlen($sValor) > 250) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 413, 'Form value too large: '.$idForm.' - '.$idField);
                    
                    // Validar que el campo de fecha tenga valor correcto
                    } elseif ($infoCampo['type'] == 'DATE' && 
                        !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $sValor) || preg_match('/^\d{4}-\d{2}-\d{2} d{2}:\d{2}:\d{2}$/', $sValor))) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 406, 
                            'Date format not acceptable, must be yyyy-mm-dd or yyyy-mm-dd hh:mm:ss: '.$idForm.' - '.$idField);
                    } else {
                        if ($infoCampo['type'] == 'LIST') {
                            // OJO: PRIMERA FORMA ANORMAL!!!
                            // La implementación actual del código de formulario
                            // agrega una coma de más al final de la lista
                            if (strlen($infoCampo['value']) > 0 && 
                                substr($infoCampo['value'], strlen($infoCampo['value']) - 1, 1) == ',') {
                                $infoCampo['value'] = substr($infoCampo['value'], 0, strlen($infoCampo['value']) - 1);
                            }
                            if (!in_array($sValor, explode(',', $infoCampo['value']))) {
                                $bDatosValidos = FALSE;
                                $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 406, 
                                    'Value not in list of accepted values: '.$idForm.' - '.$idField);
                            }
                        }                       
                    }
                    if (!$bDatosValidos) break;
                    
                    // En este punto este valor es válido y se puede generar SQL
                    if (!$recordset->execute(array($idLlamada, $idField))) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 500,
                            'Unable to check previous form value');
                    } else {
                    	$tupla = $recordset->fetch(PDO::FETCH_NUM); $recordset->closeCursor();
                        if ($tupla[0] <= 0) {
                        	$listaSQL[] = array($sth_insert, array($sValor, $idLlamada, $idField));
                        } else {
                        	$listaSQL[] = array($sth_update, array($sValor, $idLlamada, $idField));
                        }
                    }
                }
                if (!$bDatosValidos) break;
            }
            
            // Se procede a guardar los datos del formulario
            if ($bDatosValidos) {
                foreach ($listaSQL as $infoSQL) {
                    $infoSQL[0]->execute($infoSQL[1]);
                    $infoSQL[0]->closeCursor();
                }
            }
            
            if ($bDatosValidos) {
                $xml_saveFormDataResponse->addChild('success');
            }
        }

        return $xml_response;
    }

    private function Request_getpauses($comando)
    {
        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getPausesResponse = $xml_response->addChild('getpauses_response');

        $recordset = $this->_db->query(
            "SELECT id, name, status, tipo, description FROM break WHERE tipo = 'B' ORDER BY id");
        foreach ($recordset as $tupla) {
            $xml_pause = $xml_getPausesResponse->addChild('pause');
            $xml_pause->addAttribute('id', $tupla['id']);
            $xml_pause->addChild('name', str_replace('&', '&amp;', $tupla['name']));
            $xml_pause->addChild('status', str_replace('&', '&amp;', $tupla['status']));
            $xml_pause->addChild('type', str_replace('&', '&amp;', $tupla['tipo']));
            $xml_pause->addChild('description', str_replace('&', '&amp;', $tupla['description']));
        }

        return $xml_response;
    }

    /**
     * Procedimiento que implementa el login de un agente estático al estilo
     * Agent/9000. Para esta versión se asume que el agente está asociado a una
     * extensión telefónica, a la cual se mandará una llamada que conecta tal
     * extensión con la cola. El comando regresa inmediatamente. Luego el cliente
     * debe de esperar el evento LoginAgent que indica que se ha completado
     * exitosamente el login del agente, y que empezará a recibir llamadas de la
     * campaña asociada a las colas del agente.
     * 
     * Implementación: las tareas a hacer para iniciar el login del agente son:
     * 1) Verificar si el agente existe en el sistema. Si no existe, se devuelve
     *    error sin hacer otra operación.
     * 2) Verificar si la extensión indicada es válida. Si no existe, se devuelve
     *    error sin hacer otra operación. 
     * 3) Verificar si el agente ya está logoneado. Si ya está logoneado, entonces
     *    se debe verificar si está logoneado en la extensión indicada en el 
     *    parámetro. Si es la misma extensión se devuelve éxito sin hacer nada 
     *    más. Si no es la misma extensión, se devuelve error informando la 
     *    situación.
     * 4) Para agente no logoneado, se inicia un Originate entre la extensión
     *    y el canal de Agent/XXXX. Como Action-Id, se indica la cadena 
     *    "ECCP:1.0:<PID>:AgentLogin:<canaldeagente>"
     *    para distinguir este login de los logines a colas por otros motivos.
     * Para el resto del procesamiento se debe ver el método OnAgentlogin
     * en la clase DialerProcess. 
     * 
     * @param   object   $comando    Comando de login
     *      <loginagent>
     *          <agent_number>Agent/9000</agent_number>
     *          <password>xxx</password> <!-- se ignora en implementación actual -->
     *          <extension>1064</extension>
     *      </loginagent>
     * 
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *      <loginagent_response>
     *          <status>logged-out|logging|logged-in</status>
     *          <failure>mensaje</failure>
     *      </loginagent_response>
     */
    private function Request_loginagent($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente y extensión están presentes
        if (!isset($comando->agent_number) || !isset($comando->extension)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;
        $sExtension = (string)$comando->extension;

        // Verificar que la extensión y el agente son válidos en el sistema
        $listaExtensiones = $this->_listarExtensiones();
        if (!is_array($listaExtensiones)) {
            return $this->Response_LoginAgentResponse('logged-out', 500, 'Failed to list extensions');
        }
        $listaAgentes = $this->_listarAgentes();
        if (!in_array($sAgente, array_keys($listaAgentes))) {
            return $this->Response_LoginAgentResponse('logged-out', 404, 'Specified agent not found');
        } elseif (!in_array($sExtension, array_keys($listaExtensiones))) {
            return $this->Response_LoginAgentResponse('logged-out', 404, 'Specified extension not found');
        }
        
        // Verificar el hash del agente
        if (!$this->_hashValidoAgenteECCP($comando)) {
            return $this->Response_LoginAgentResponse('logged-out', 401, 'Unauthorized agent');
        }

        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
        	return $this->Response_LoginAgentResponse('logged-out', 404, 'Specified agent not found');
        }
        if (!is_null($infoSeguimiento['extension'])) {
            /* No se puede aceptar que el agente esté ya logoneado, incluso
             * con la extensión que se ha pedido, porque no se tiene la
             * información de estado del agente (Uniqueid, id_sesion, etc)
             * hasta que se implemente la recolección de tales variables
             * a partir de Asterisk y la base de datos call_center. La 
             * excepción es si el programa ya hace seguimiento del agente
             * indicado. */
        	if ($infoSeguimiento['extension'] == $listaExtensiones[$sExtension]) {
                // Ya se ha iniciado el login del agente
                $sEstadoReportar = $infoSeguimiento['estado_consola'];
                if ($sEstadoReportar == 'logged-out') $sEstadoReportar = 'logging';
                return $this->Response_LoginAgentResponse($infoSeguimiento['estado_consola']);
        	} else {
                // Otra extensión ya ocupa el login del agente indicado
                return $this->Response_LoginAgentResponse('logged-out', 409,
                    'Specified agent already connected to extension: '.$infoSeguimiento['extension']);
        	}
        } else {
            // No hay canal de login. Se inicia login a través de Originate
            $r = $this->_loginAgente($listaExtensiones[$sExtension], $sAgente);
            return $this->Response_LoginAgentResponse('logging');            
        }
    }

    // Función que encapsula la generación de la respuesta
    private function Response_LoginAgentResponse($status, $iCodigo = NULL, $msg = NULL)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_loginAgentResponse = $xml_response->addChild('loginagent_response');

        $xml_loginAgentResponse->addChild('status', $status);
        if (!is_null($msg)) 
            $this->_agregarRespuestaFallo($xml_loginAgentResponse, $iCodigo, $msg);
            
        return $xml_response;           
    }
    
    // TODO: encontrar manera elegante de tener una sola definición
    private function _abrirConexionFreePBX()
    {
        $sNombreConfig = '/etc/amportal.conf';  // TODO: vale la pena poner esto en config?

        // De algunas pruebas se desprende que parse_ini_file no puede parsear 
        // /etc/amportal.conf, de forma que se debe abrir directamente.
        $dbParams = array();
        $hConfig = fopen($sNombreConfig, 'r');
        if (!$hConfig) {
            $this->_log->output('ERR: no se puede abrir archivo '.$sNombreConfig.' para lectura de parámetros FreePBX.');
            return NULL;
        }
        while (!feof($hConfig)) {
            $sLinea = fgets($hConfig);
            if ($sLinea === FALSE) break;
            $sLinea = trim($sLinea);
            if ($sLinea == '') continue;
            if ($sLinea{0} == '#') continue;
            
            $regs = NULL;
            if (preg_match('/^([[:alpha:]]+)[[:space:]]*=[[:space:]]*(.*)$/', $sLinea, $regs)) switch ($regs[1]) {
            case 'AMPDBHOST':
            case 'AMPDBUSER':
            case 'AMPDBENGINE':
            case 'AMPDBPASS':
                $dbParams[$regs[1]] = $regs[2];
                break;
            }
        }
        fclose($hConfig); unset($hConfig);
        
        // Abrir la conexión a la base de datos, si se tienen todos los parámetros
        if (count($dbParams) < 4) {
            $this->_log->output('ERR: archivo '.$sNombreConfig.
                ' de parámetros FreePBX no tiene todos los parámetros requeridos para conexión.');
            return NULL;
        }
        if ($dbParams['AMPDBENGINE'] != 'mysql' && $dbParams['AMPDBENGINE'] != 'mysqli') {
            $this->_log->output('ERR: archivo '.$sNombreConfig.
                ' de parámetros FreePBX especifica AMPDBENGINE='.$dbParams['AMPDBENGINE'].
                ' que no ha sido probado.');
            return NULL;
        }
        try {
            $dbConn = new PDO("mysql:host={$dbParams['AMPDBHOST']};dbname=asterisk", 
                $dbParams['AMPDBUSER'], $dbParams['AMPDBPASS']);
            $dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $dbConn;
        } catch (PDOException $e) {
            $this->_log->output("ERR: no se puede conectar a DB de FreePBX - ".
                $e->getMessage());
        	return NULL;
        }        
    }

    /**
     * Método que lista todas las extensiones SIP e IAX que están definidas en
     * el sistema. Estas extensiones pueden ser usadas por el agente para 
     * logonearse en el sistema. La lista se devuelve de la forma 
     * (1000 => 'SIP/1000'), ...
     *
     * @return  mixed   La lista de extensiones.
     */
    private function _listarExtensiones()
    {
        // TODO: verificar si esta manera de consultar funciona para todo 
        // FreePBX. Debe de poder identificarse extensiones sin asumir una 
        // tecnología en particular. 
        $oDB = $this->_abrirConexionFreePBX();
        if (is_null($oDB)) return NULL;
        try {
            $sPeticion = <<<LISTA_EXTENSIONES
SELECT extension,
    (SELECT COUNT(*) FROM iax WHERE iax.id = users.extension) AS iax,
    (SELECT COUNT(*) FROM sip WHERE sip.id = users.extension) AS sip
FROM users ORDER BY extension
LISTA_EXTENSIONES;
            $recordset = $oDB->query($sPeticion);
            $listaExtensiones = array();
            foreach ($recordset as $tupla) {
                $sTecnologia = NULL;
                if ($tupla['iax'] > 0) $sTecnologia = 'IAX2/';
                if ($tupla['sip'] > 0) $sTecnologia = 'SIP/';
                
                // Cómo identifico las otras tecnologías?
                if (!is_null($sTecnologia)) {
                    $listaExtensiones[$tupla['extension']] = $sTecnologia.$tupla['extension'];
                }
            }
        } catch (PDOException $e) {
        	$this->_log->output('ERR: (internal) Cannot list extensions - '.$e->getMessage());
        }
        $oDB = NULL;
        return $listaExtensiones;
    }

    /**
     * Método que lista todos los agentes registrados en la base de datos. La
     * lista se devuelve de la forma (9000 => 'Over 9000!!!'), ...
     *
     * @return  mixed   La lista de agentes activos
     */
    private function _listarAgentes()
    {
        $sPeticion = "SELECT type, number, name FROM agent WHERE estatus = 'A' ORDER BY number";
        foreach ($this->_db->query($sPeticion) as $tupla) {
        	$listaAgentes[$tupla['type'].'/'.$tupla['number']] = $tupla['number'].' - '.$tupla['name'];
        }
        return $listaAgentes;
    }

    /**
     * Método para iniciar el login del agente con la extensión y el número de
     * agente que se indican. Se asume que el agente es válido en el sistema.
     *
     * @param   string  Extensión que está usando el agente, como "SIP/1064"
     * @param   string  Cadena del agente que se está logoneando: "Agent/9000"
     *
     * @return  VERDADERO en éxito, FALSE en error
     */
    private function _loginAgente($sExtension, $sAgente)
    {
        $this->_tuberia->AMIEventProcess_agregarIntentoLoginAgente($sAgente, $sExtension);
        $agentFields = $this->_parseAgent($sAgente);
        if ($agentFields['type'] == 'Agent') {
            $r = $this->_ami->Originate(
                $sExtension,        // channel
                NULL, NULL, NULL,   // extension, context, priority
                'AgentLogin',       // application
                $agentFields['number'],        // data
                NULL, NULL, NULL, NULL,
                TRUE,               // async
                'ECCP:1.0:'.posix_getpid().':AgentLogin:'.$sAgente     // action-id
                );
        } else {
            /* 
             * Deben obtenerse las colas en las que la extension es Dynamic Member. 
             * Las colas a continuación están quemadas por el momento. 
             * La contraseña debe ingresarse desde un input en la interfaz de login y validarse en el servidor. 
             * No es necesario que Asterisk genere una llamada a la extensión pra validar la contraseña.
             */
            $arrColas = $this->_getQueuesGivenDynamicMember($agentFields['type'], $agentFields['number']);
    
            // TODO: Falta validar, que ocurre si no hay colas habria que cancelarIntentoLogin
            foreach($arrColas as $cola) {  
                // Lo saco de todas las colas ...
                $r = $this->_ami->QueueRemove($cola, $sAgente);
        
                // Para volverlos a agregar aqui.
                $r = $this->_ami->QueueAdd($cola, $sAgente);
            }
        }
        if ($r['Response'] != 'Success')
            $this->_tuberia->AMIEventProcess_cancelarIntentoLoginAgente($sAgente);
        return $r;
    }

    private function _getQueuesGivenDynamicMember($sAgentType, $sAgentNumber)
    {
        // $key_input tomaría la forma agents/S100 (para SIP) ó agents/I110 (para IAX)
        $extension = $sAgentType{0}.$sAgentNumber; 
        $db_output = $this->_ami->database_showkey('agents/'.$extension); 
    
        $arrColas = array();
        foreach($db_output as $k => $val){  
            $preg_match_string = "|^/QPENALTY/(\d+)/agents/$extension$|";
            if (preg_match($preg_match_string, $k, $regs)) {     
                $arrColas[] = $regs[1];
            }
        }
        return $arrColas;
    }

    /**
     * Procedimiento que implementa el logoff de un agente estático al estilo
     * Agent/9000.
     * 
     * Implementación: las tareas a hacer para iniciar el login del agente son:
     * 1) Verificar si el agente existe en el sistema. Si no existe, se devuelve
     *    error sin hacer otra operación.
     * 2) El logoff sólo está implementado para agentes de tipo Agent/9000. Si
     *    se especifica otro tipo de agente, se rechaza con error de no 
     *    implementado. De otro modo, se recoge el número de agente (9000)
     * 3) Se ejecuta el comando de AMI Agentlogoff() con el número de agente
     * Para el resto del procesamiento se debe ver el método OnAgentlogoff en
     * la clase DialerProcess.
     * 
     * @param   object   $comando    Comando de logout
     *      <logoutagent>
     *          <agent_number>Agent/9000</agent_number>
     *      </logoutagent>
     * 
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *      <logoutagent_response>
     *          <status>logged-out</status>
     *          <failure>mensaje</failure>
     *      </logoutagent_response>
     */
    private function Request_logoutagent($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presentes
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        // Verificar que el agente sea válido en el sistema
        $listaAgentes = $this->_listarAgentes();
        if (!in_array($sAgente, array_keys($listaAgentes))) {
            return $this->Response_LogoutAgentResponse('logged-out', 404, 'Specified agent not found');
        }

        // Verificar el hash del agente
        if (!$this->_hashValidoAgenteECCP($comando)) {
            return $this->Response_LogoutAgentResponse('logged-out', 401, 'Unauthorized agent');
        }

        // Canal que hizo el logoneo hacia la cola
        $infoAgente = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);

        /* Ejecutar Agentlogoff. Esto asume que el agente está de la forma 
         * Agent/9000. La actualización de las bases de datos de auditoría y 
         * breaks se delega a los manejadores de eventos */
        $agentFields = $this->_parseAgent($sAgente);
        if ($agentFields['type'] == 'Agent') {
            $r = $this->_ami->Agentlogoff($agentFields['number']);
            
            /* Si el agente todavía no ha introducido la clave, el Agentlogoff
             * anterior no tiene efecto, así que se manda a colgar el canal
             * directamente. 
             */
            if (!is_null($infoAgente) && $infoAgente['estado_consola'] == 'logging') {
                $sCanalExt = $infoAgente['login_channel'];
                if (is_null($sCanalExt)) $sCanalExt = $infoAgente['extension'];
                if (!is_null($sCanalExt)) $this->_ami->Hangup($sCanalExt);
            }
            return $this->Response_LogoutAgentResponse('logged-out');
        } else {
            // Si hay cliente conectado, le cierro el canal.
            if (!is_null($infoAgente['clientchannel'])) {
                $this->_ami->Hangup($infoAgente['clientchannel']);
            }
 
            // Lo saco de todas las colas ...
            $arrColas = $this->_getQueuesGivenDynamicMember($agentFields['type'], $agentFields['number']);      
            foreach ($arrColas as $cola) {  
                $r = $this->_ami->QueueRemove($cola, $sAgente);
            }
            return $this->Response_LogoutAgentResponse('logged-out');       
        }
    }

    // Función que encapsula la generación de la respuesta
    private function Response_LogoutAgentResponse($status, $iCodigo = NULL, $msg = NULL)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_loginAgentResponse = $xml_response->addChild('logoutagent_response');

        $xml_loginAgentResponse->addChild('status', $status);
        if (!is_null($msg))
            $this->_agregarRespuestaFallo($xml_loginAgentResponse, $iCodigo, $msg);                
        return $xml_response;           
    }

    private function Request_pauseagent($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presente
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        // Verificar que ID de break está presente
        if (!isset($comando->pause_type))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idBreak = (int)$comando->pause_type;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_pauseAgentResponse = $xml_response->addChild('pauseagent_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 417, 'Invalid agent number');
            return $xml_response;
        }

        // Verificar que el agente está autorizado a realizar operación
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        // Verificar si el agente está siendo monitoreado y que no esté en pausa
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 404, 'Agent not found or not logged in through ECCP');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 417, 'Agent currenty not logged in');
            return $xml_response;
        }
        if (!is_null($infoSeguimiento['id_break'])) { 
            if ($infoSeguimiento['id_break'] != $idBreak) {
                // Agente ya estaba en otro break
                $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 417, 'Agent already in incompatible break');
            } else {
                // Agente ya estaba en el mismo break
            	$xml_pauseAgentResponse->addChild('success');
            }
            return $xml_response;
        }

        // Verificar si la pausa indicada existe y está activa
        $recordset = $this->_db->prepare(
            'SELECT id, name FROM break WHERE tipo = "B" AND status = "A" AND id = ?');
        $recordset->execute(array($idBreak));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if (!$tupla) {
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 404, 'Break ID not found or not active');
            return $xml_response;
        }

        // Ejecutar la pausa a través del AMI. 
        /* TODO: puede haber una carrera si dos o más conexiones intentan hacer
         * que el mismo agente entre en break al mismo tiempo.
         */
        if ($infoSeguimiento['num_pausas'] == 0) {
            $r = $this->_ami->QueuePause(NULL, $sAgente, 'true');
            if ($r['Response'] != 'Success') {
                $this->_log->output('ERR: '.__METHOD__.' (internal) no se puede poner al agente en pausa: '.
                    $sAgente.' - '.$r['Message']);
                $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 500, 'Unable to start agent break');
                return $xml_response;
            }
        }
        $iTimestampInicioPausa = time();
        
        // Mandar a escribir el inicio de la pausa a la base de datos
        $idAuditBreak = $this->_eccpProcess->marcarInicioBreakAgente(
            $infoSeguimiento['id_agent'], $idBreak, $iTimestampInicioPausa);
        if (is_null($idAuditBreak)) {
            if ($infoSeguimiento['num_pausas'] == 0) {
            	$r = $this->_ami->QueuePause(NULL, $sAgente, 'false');
            }
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 500, 'Unable to start agent break');
            return $xml_response;
        }
        
        // Notificar éxito en inicio de break
        $this->_tuberia->msg_AMIEventProcess_idNuevoBreakAgente($sAgente, $idBreak, $idAuditBreak);
        $this->multiplexSrv->notificarEvento_PauseStart($sAgente, array(
            'pause_class'   =>  'break',
            'pause_type'    =>  $idBreak,
            'pause_name'    =>  $tupla['name'],
            'pause_start'   =>  date('Y-m-d H:i:s', $iTimestampInicioPausa),
        ));

        $xml_pauseAgentResponse->addChild('success');        
        return $xml_response;
    }

    private function Request_unpauseagent($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presente
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_unpauseAgentResponse = $xml_response->addChild('unpauseagent_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_unpauseAgentResponse, 417, 'Invalid agent number');
            return $xml_response;
        }

        // Verificar que el agente está autorizado a realizar operación
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_unpauseAgentResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_unpauseAgentResponse, 404, 'Agent not found or not logged in through ECCP');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_unpauseAgentResponse, 417, 'Agent currenty not logged in');
            return $xml_response;
        }
        if (is_null($infoSeguimiento['id_break'])) {
            // Si el agente no estaba en break, se devuelve éxito sin hacer nada
            $xml_unpauseAgentResponse->addChild('success');
        	return $xml_response;
        }

        // Ejecutar el final de pausa a través del AMI
        if ($infoSeguimiento['num_pausas'] > 0) {
            $r = $this->_ami->QueuePause(NULL, $sAgente, 'false');
            if ($r['Response'] != 'Success') {
                $this->_log->output('ERR: (internal) no se puede sacar al agente de pausa: '.
                    $sAgente.' - '.$r['Message']);
                $this->_agregarRespuestaFallo($xml_unpauseAgentResponse, 500, 'Unable to stop agent break');
                return $xml_response;
            }
        }
        $iTimestampFinalPausa = time();
        $this->_tuberia->msg_AMIEventProcess_quitarBreakAgente($sAgente);
        if (!$this->_eccpProcess->marcarFinalBreakAgente(
            $infoSeguimiento['id_audit_break'], $iTimestampFinalPausa)) {
            $this->_agregarRespuestaFallo($xml_unpauseAgentResponse, 500, 'Unable to write stop of agent break');
            return $xml_response;
        }
        
        $xml_unpauseAgentResponse->addChild('success');
        
        $this->_eccpProcess->lanzarEventoPauseEnd($sAgente, 
            $infoSeguimiento['id_audit_break'], 'break');

        return $xml_response;
    }

    /**
     * Procedimiento que implementa la verificación del estado de un agente 
     * estático al estilo Agent/9000.
     * 
     * @param   object   $comando    Comando
     *      <getagentstatus>
     *          <agent_number>Agent/9000</agent_number>
     *      </getagentstatus>
     * 
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *      <getagentstatus_response>
     *          <status>offline|online|oncall|paused</status>
     *          <channel>SIP/1064-000000001</channel>
     *          <extension>1064<extension/>
     *          <failure>mensaje</failure>
     *      </getagentstatus_response>
     */
    private function Request_getagentstatus($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        $iTimestampInicio = microtime(TRUE);

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');
        
        // Verificar que agente está presentes
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getAgentStatusResponse = $xml_response->addChild('getagentstatus_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $xml_getAgentStatusResponse->addChild('status', 'offline');
            $this->_agregarRespuestaFallo($xml_getAgentStatusResponse, 404, 'Invalid agent number');
            return $xml_response;
        }

        // Obtener la información del estado del agente según el marcador
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $xml_getAgentStatusResponse->addChild('status', 'offline');
            $this->_agregarRespuestaFallo($xml_getAgentStatusResponse, 404, 'Invalid agent number');
            return $xml_response;
        }
        
        // Canal que hizo el logoneo hacia la cola
        $sExtension = NULL;
        $sCanalExt = $infoSeguimiento['login_channel'];
        if (is_null($sCanalExt)) $sCanalExt = $infoSeguimiento['extension'];
        if (!is_null($sCanalExt)) {
            // Hay un canal de login. Se separa la extensión que hizo el login
            $sRegexp = "|^\w+/(\\d+)-?|"; $regs = NULL;
            if (preg_match($sRegexp, $sCanalExt, $regs)) {
                $sExtension = $regs[1];
            }
        }
        
        // Reportar los estados conocidos
        $bEstadoConocido = FALSE;
        if ($infoSeguimiento['num_pausas'] > 0) {
            $xml_getAgentStatusResponse->addChild('status', 'paused');
            $bEstadoConocido = TRUE;
        } elseif ($infoSeguimiento['oncall']) {
            $xml_getAgentStatusResponse->addChild('status', 'oncall');
            $bEstadoConocido = TRUE;
        } elseif ($infoSeguimiento['estado_consola'] == 'logged-in') {
            $xml_getAgentStatusResponse->addChild('status', 'online');
            $bEstadoConocido = TRUE;
        } else {
            $xml_getAgentStatusResponse->addChild('status', 'offline');
            $bEstadoConocido = TRUE;
        }

        // Reportar el canal remoto al cual está conectado el agente
        if (!is_null($infoSeguimiento['clientchannel'])) {
            $xml_getAgentStatusResponse->addChild('remote_channel', $infoSeguimiento['clientchannel']);
        }

        // Reportar los estados de break y hold, si aplican
        if (!is_null($infoSeguimiento['id_break'])) {
            $xml_pauseInfo = $xml_getAgentStatusResponse->addChild('pauseinfo');
            $xml_pauseInfo->addChild('pauseid', $infoSeguimiento['id_break']);
            
            // Leer fecha de inicio y nombre del break
            $recordset = $this->_db->prepare(
                'SELECT audit.datetime_init, break.name FROM audit, break '.
                'WHERE audit.id = ? AND audit.id_break = break.id');
            $recordset->execute(array($infoSeguimiento['id_audit_break']));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            $xml_pauseInfo->addChild('pausename', str_replace('&', '&amp;', $tupla['name']));
            $xml_pauseInfo->addChild('pausestart', str_replace(date('Y-m-d '), '', $tupla['datetime_init']));
        }
        if ($infoSeguimiento['estado_consola'] == 'logged-in')
            $xml_getAgentStatusResponse->addChild('onhold', is_null($infoSeguimiento['id_hold']) ? 0 : 1);

        // Reportar los estado de llamadas saliente y entrante
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (!is_null($infoLlamada)) {
            $xml_callInfo = $xml_getAgentStatusResponse->addChild('callinfo');
            $xml_callInfo->addChild('calltype', $infoLlamada['calltype']);
            $xml_callInfo->addChild('callid', $infoLlamada['callid']);
            if (!is_null($infoLlamada['campaign_id']))
                $xml_callInfo->addChild('campaign_id', $infoLlamada['campaign_id']);
            $xml_callInfo->addChild('callnumber', $infoLlamada['dialnumber']);
            if (isset($infoLlamada['datetime_dialstart']))
                $xml_callInfo->addChild('dialstart', str_replace(date('Y-m-d '), '', $infoLlamada['datetime_dialstart']));
            if (isset($infoLlamada['datetime_dialend']))
                $xml_callInfo->addChild('dialend', str_replace(date('Y-m-d '), '', $infoLlamada['datetime_dialend']));
            $xml_callInfo->addChild('queuestart', str_replace(date('Y-m-d '), '', $infoLlamada['datetime_enterqueue']));
            $xml_callInfo->addChild('linkstart', str_replace(date('Y-m-d '), '', $infoLlamada['datetime_linkstart']));
            if (!is_null($infoLlamada['queuenumber']))
                $xml_callInfo->addChild('queuenumber', $infoLlamada['queuenumber']);
        }

        if ($bEstadoConocido) {
            if (!is_null($sCanalExt)) $xml_getAgentStatusResponse->addChild('channel', str_replace('&', '&amp;', $sCanalExt));
            if (!is_null($sExtension)) $xml_getAgentStatusResponse->addChild('extension', $sExtension);
        } else {
            $xml_getAgentStatusResponse->addChild('status', 'offline');
            $this->_agregarRespuestaFallo($xml_getAgentStatusResponse, 500, 'Unknown status');
        }

        return $xml_response;
    }

    private function Request_hangup($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presentes
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_hangupResponse = $xml_response->addChild('hangup_response');

        // Verificar que el agente sea válido en el sistema
        $listaAgentes = $this->_listarAgentes();
        if (!in_array($sAgente, array_keys($listaAgentes))) {
            $this->_agregarRespuestaFallo($xml_hangupResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        // Verificar el hash del agente
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_hangupResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada) || is_null($infoLlamada['agentchannel'])) {
            $this->_agregarRespuestaFallo($xml_hangupResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Mandar a colgar la llamada usando el canal Agent/9000
        $r = $this->_ami->Hangup($infoLlamada['agentchannel']);
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: No se puede colgar la llamada para '.$sAgente.
                ' ('.$infoLlamada['agentchannel'].') - '.$r['Message']);
            $this->_agregarRespuestaFallo($xml_hangupResponse, 500, 'Cannot hangup agent call');
            return $xml_response;
        }

        $xml_hangupResponse->addChild('success');
        return $xml_response;
    }
    
    private function Request_getcampaignstatus($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que id y tipo está presente
        if (!isset($comando->campaign_id)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idCampania = (int)$comando->campaign_id;
        $sTipoCampania = 'outgoing';
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }

        // Leer información de las llamadas en curso para la campaña
        $statusCampania_AMI = $this->_tuberia->AMIEventProcess_reportarInfoLlamadasCampania($sTipoCampania, $idCampania);
        
        // Leer resumen de llamadas completadas desde la base de datos
        switch ($sTipoCampania) {
        case 'outgoing':
            $statusCampania_DB = $this->_leerResumenCampaniaSaliente($idCampania);
            break;
        case 'incoming':
            $statusCampania_DB = $this->_leerResumenCampaniaEntrante($idCampania);
            break;
        default:
            return $this->_generarRespuestaFallo(400, 'Bad request');
        }

        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCampaignStatusResponse = $xml_response->addChild('getcampaignstatus_response');
        if (count($statusCampania_DB) <= 0) {
            $this->_agregarRespuestaFallo($xml_GetCampaignStatusResponse, 404, 'Campaign not found');
            return $xml_response;
        }

        // Cuentas de estados de llamadas realizadas
        $xml_statusCount = $xml_GetCampaignStatusResponse->addChild('statuscount');
        $xml_statusCount->addChild('total', array_sum($statusCampania_DB['status']));
        foreach ($statusCampania_DB['status'] as $statusKey => $statusCount)
            $xml_statusCount->addChild(strtolower($statusKey), $statusCount);
            
        if (!function_exists('_getcampaignstatus_setagent')) {
            function _getcampaignstatus_setagent($xml_agent, $infoAgente)
            {
                if ($infoAgente['num_pausas'] > 0) {
                    $xml_agent->addChild('status', 'paused');
                } elseif ($infoAgente['oncall']) {
                    $xml_agent->addChild('status', 'oncall');
                } elseif ($infoAgente['estado_consola'] == 'logged-in') {
                    $xml_agent->addChild('status', 'online');
                } else {
                    $xml_agent->addChild('status', 'offline');
                }
                if (isset($infoAgente['callid']))
                    $xml_agent->addChild('callid', $infoAgente['callid']);
                if (isset($infoAgente['dialnumber']))
                    $xml_agent->addChild('callnumber', $infoAgente['dialnumber']);
                if (isset($infoAgente['clientchannel']))
                    $xml_agent->addChild('callchannel', str_replace('&', '&amp;', $infoAgente['clientchannel']));
                if (isset($infoAgente['datetime_dialstart']))
                    $xml_agent->addChild('dialstart', str_replace(date('Y-m-d '), '', $infoAgente['datetime_dialstart']));
                if (isset($infoAgente['datetime_dialend']))
                    $xml_agent->addChild('dialend', str_replace(date('Y-m-d '), '', $infoAgente['datetime_dialend']));
                if (isset($infoAgente['datetime_enterqueue']))
                    $xml_agent->addChild('queuestart', str_replace(date('Y-m-d '), '', $infoAgente['datetime_enterqueue']));
                if (isset($infoAgente['datetime_linkstart']))
                    $xml_agent->addChild('linkstart', str_replace(date('Y-m-d '), '', $infoAgente['datetime_linkstart']));
                if (isset($infoAgente['trunk']))
                    $xml_agent->addChild('trunk', $infoAgente['trunk']);
    
                if (!is_null($infoAgente['id_break'])) {
                    $xml_agent->addChild('pauseid', $infoAgente['id_break']);
                    $recordset = $this->_db->prepare(
                        'SELECT audit.datetime_init, break.name, break.id '.
                        'FROM audit, break WHERE audit.id_break = break.id AND audit.id = ?');
                    $recordset->execute(array($infoAgente['id_audit_break']));
                    $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
                    $recordset->closeCursor();
                    if ($tupla) {
                        $xml_agent->addChild('pausename', str_replace('&', '&amp;', $tupla['name']));
                        $xml_agent->addChild('pausestart', str_replace(date('Y-m-d '), '', $tupla['datetime_init']));
                    }
                }
            }
        }
        
        // Estado de los agentes
        $xml_agents = $xml_GetCampaignStatusResponse->addChild('agents');
        foreach ($statusCampania_AMI['queuestatus'] as $sAgente => $infoAgente) {
            // Este código asume agentes de formato Agent/9000
            $xml_agent = $xml_agents->addChild('agent');
            $xml_agent->addChild('agentchannel', $sAgente);
            
            _getcampaignstatus_setagent($xml_agent, $infoAgente);
        }
        
        // Estado de los agentes logoneados en la cola
        $listaAgentes = array_unique(array_merge(
            $this->_listarAgentesLogoneadosCola($statusCampania_DB['queue']),
            $this->_listarAgentesDinamicosCola($statusCampania_DB['queue'])));
        foreach ($listaAgentes as $sAgente) if (!isset($statusCampania_AMI['queuestatus'][$sAgente])) {
        	$infoAgente = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
            if (!is_null($infoAgente)) {
                $xml_agent = $xml_agents->addChild('agent');
                $xml_agent->addChild('agentchannel', $sAgente);
            
                _getcampaignstatus_setagent($xml_agent, $infoAgente);
            }
        }

        // Estado de las llamadas pendientes de enlazar
        $xml_activecalls = $xml_GetCampaignStatusResponse->addChild('activecalls');
        foreach ($statusCampania_AMI['activecalls'] as $infoLlamada) {
            $xml_activecall = $xml_activecalls->addChild('activecall');
            $xml_activecall->addChild('callnumber', $infoLlamada['dialnumber']);
            $xml_activecall->addChild('callid', $infoLlamada['callid']);
            $xml_activecall->addChild('callstatus', strtolower($infoLlamada['callstatus']));
            if (isset($infoLlamada['datetime_dialstart']))
                $xml_activecall->addChild('dialstart', str_replace(date('Y-m-d '), '', $infoLlamada['datetime_dialstart']));
            if (isset($infoLlamada['datetime_dialend']))
                $xml_activecall->addChild('dialend', str_replace(date('Y-m-d '), '', $infoLlamada['datetime_dialend']));
            if (isset($infoLlamada['datetime_enterqueue']))
                $xml_activecall->addChild('queuestart', str_replace(date('Y-m-d '), '', $infoLlamada['datetime_enterqueue']));
            if (isset($infoLlamada['trunk']))
                $xml_activecall->addChild('trunk', $infoLlamada['trunk']);
        }
        
        return $xml_response;
    }
    

    /**
     * Método que devuelve un resumen de la información de una campaña saliente
     * para ser mostrada en la interfaz de monitoreo.
     *
     * @param   int     $idCampania     ID de la campaña a interrogar
     *
     * @return  mixed   NULL en error, o información de la campaña
     */
    private function _leerResumenCampaniaSaliente($idCampania)
    {
        // Leer la información en el propio registro de la campaña
        $sPeticionSQL = <<<LEER_RESUMEN_CAMPANIA
SELECT id, name, datetime_init, datetime_end, daytime_init, daytime_end, 
    retries, trunk, queue, estatus
FROM campaign WHERE id = ?
LEER_RESUMEN_CAMPANIA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if (!$tupla) return array();

        // Leer la clasificación por estado de las llamadas de la campaña
        $sPeticionSQL = 'SELECT COUNT(*) AS n, status FROM calls WHERE id_campaign = ? GROUP BY status';
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania));
        $recordset->setFetchMode(PDO::FETCH_ASSOC);
        $tupla['status'] = array(
            'Pending'   =>  0,  // Llamada no ha sido realizada todavía

            'Placing'   =>  0,  // Originate realizado, no se recibe OriginateResponse
            'Ringing'   =>  0,  // Se recibió OriginateResponse, no entra a cola
            'OnQueue'   =>  0,  // Entró a cola, no se asigna a agente todavía
            'Success'   =>  0,  // Conectada y asignada a un agente
            'OnHold'    =>  0,  // Llamada fue puesta en espera por agente
            'Failure'   =>  0,  // No se puede conectar llamada
            'ShortCall' =>  0,  // Llamada conectada pero duración es muy corta
            'NoAnswer'  =>  0,  // Llamada estaba Ringing pero no entró a cola
            'Abandoned' =>  0,  // Llamada estaba OnQueue pero no habían agentes            
        );
        foreach ($recordset as $tuplaStatus) {
            if (is_null($tuplaStatus['status']))
                $tupla['status']['Pending'] = $tuplaStatus['n'];
            else $tupla['status'][$tuplaStatus['status']] = $tuplaStatus['n'];
        }

        return $tupla;
    }

    private function _leerResumenCampaniaEntrante($idCampania)
    {
        // Leer la información en el propio registro de la campaña
        $sPeticionSQL = <<<LEER_RESUMEN_CAMPANIA
SELECT ce.id, ce.name, ce.datetime_init, ce.datetime_end, ce.daytime_init, 
    ce.daytime_end, qce.queue, ce.estatus 
FROM campaign_entry ce, queue_call_entry qce 
WHERE ce.id = ? AND ce.id_queue_call_entry = qce.id
LEER_RESUMEN_CAMPANIA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if (!$tupla) return array();

        // Leer la clasificación por estado de las llamadas de la campaña
        $sPeticionSQL = 'SELECT COUNT(*) AS n, status FROM call_entry WHERE id_campaign = ? GROUP BY status';
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania));
        $recordset->setFetchMode(PDO::FETCH_ASSOC);
        $tupla['status'] = array(
            //'Pending'   =>  0,  // Llamada no ha sido realizada todavía

            //'Placing'   =>  0,  // Originate realizado, no se recibe OriginateResponse
            //'Ringing'   =>  0,  // Se recibió OriginateResponse, no entra a cola
            'OnQueue'   =>  0,  // Entró a cola, no se asigna a agente todavía
            'Success'   =>  0,  // Conectada y asignada a un agente
            'OnHold'    =>  0,  // Llamada fue puesta en espera por agente
            //'Failure'   =>  0,  // No se puede conectar llamada
            //'ShortCall' =>  0,  // Llamada conectada pero duración es muy corta
            //'NoAnswer'  =>  0,  // Llamada estaba Ringing pero no entró a cola
            'Abandoned' =>  0,  // Llamada estaba OnQueue pero no habían agentes
            'Finished'  =>  0,  // Llamada ha terminado luego de ser conectada a agente
            'LostTrack' =>  0,  // Programa fue terminado mientras la llamada estaba activa            
        );
        $mapaEstados = array(
            'en-cola'       =>  'OnQueue',
            'activa'        =>  'Success',
            'hold'          =>  'OnHold',
            'abandonada'    =>  'Abandoned',             
            'terminada'     =>  'Finished',
            'fin-monitoreo' =>  'LostTrack',
        );
        foreach ($recordset as $tuplaStatus) {
            $tupla['status'][$mapaEstados[$tuplaStatus['status']]] = $tuplaStatus['n'];
        }

        return $tupla;
    }

    private function Request_schedulecall($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presente
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_scheduleResponse = $xml_response->addChild('schedulecall_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, 417, 'Invalid agent number');
            return $xml_response;
        }

        // Verificar que el agente está autorizado a realizar operación
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, 404, 'Agent not found or not logged in through ECCP');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, 417, 'Agent currenty not logged in');
            return $xml_response;
        }

        $bMismoAgente = FALSE;
        $horario = NULL;
        $sNuevoTelefono = NULL;
        $sNuevoNombre = NULL;
        
        // Verificar si se debe usar el mismo agente (requiere contexto especial)
        if (isset($comando->sameagent) && (int)$comando->sameagent != 0)
            $bMismoAgente = TRUE;
        
        // Verificar si se debe usar un nuevo teléfono
        if (isset($comando->newphone)) $sNuevoTelefono = (string)$comando->newphone;
        
        // Verificar si se debe usar un nuevo nombre de contacto
        if (isset($comando->newcontactname)) $sNuevoNombre = (string)$comando->newcontactname;
        
        // Verificar que se tiene un horario establecido
        if (isset($comando->schedule)) {
            if (isset($comando->schedule->date_init) && isset($comando->schedule->date_end) && 
                isset($comando->schedule->time_init) && isset($comando->schedule->time_end)) {
                $horario = array(
                    'date_init' =>  (string)$comando->schedule->date_init,
                    'date_end'  =>  (string)$comando->schedule->date_end,
                    'time_init' =>  (string)$comando->schedule->time_init,
                    'time_end'  =>  (string)$comando->schedule->time_end,
                );
            } else {
                $this->_agregarRespuestaFallo($xml_scheduleResponse, 400, 'Bad request: incomplete schedule');
                return $xml_response;
            }
        }

        if ($bMismoAgente && is_null($horario)) {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, 400, 'Bad request: same-agent requires schedule');
            return $xml_response;
        }

        // Ejecutar el agendamiento de la llamada
        $errcode = $errdesc = NULL;
        $bExito = $this->_agendarLlamadaAgente($sAgente, $horario, 
            $bMismoAgente, $sNuevoTelefono, $sNuevoNombre, $errcode, $errdesc);
        if (!$bExito) {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, $errcode, $errdesc);
        } else {
            $xml_scheduleResponse->addChild('success');
        }

        return $xml_response;
    }

    /**
     * Procedimiento que crea una nueva llamada agendada en base a la llamada
     * que está atendiendo el agente indicado por el parámetro.
     * 
     * @param   string  $sAgente        Agente en formato Agent/9000
     * @param   mixed   $horario        Arreglo que define el horario como sigue:
     *          date_init               Fecha en inicio de horario en formato YYYY-MM-DD
     *          date_end                Fecha de fin de horario en formato YYYY-MM-DD
     *          time_init               Hora de inicio de horario en formato HH:MM:SS
     *          time_end                Hora de fin de horario en formato HH:MM:SS
     *                                  NULL para agendar llamada al final de campaña
     *                                  a cualquier fecha y hora
     * @param   bool    $bMismoAgente   FALSO si se asigna llamada a cualquier agente
     *                                  VERDADERO para que el mismo agente deba atenderla
     *                                  Si VERDADERO, se requiere $horario.
     * @param   mixed   $sNuevoTelefono Teléfono nuevo al cual marcar llamada, o NULL para mismo anterior
     * @param   mixed   $sNuevoNombre   Nombre del nuevo contacto para llamada, o NULL para mismo anterior
     * 
     * @return bool VERDADERO en caso de éxito, FALSO en caso de error
     */
    function _agendarLlamadaAgente($sAgente, $horario, $bMismoAgente, 
        $sNuevoTelefono, $sNuevoNombre, &$errcode, &$errdesc)
    {
        $errcode = 0; $errdesc = 'Success';

        // Revisar teléfono nuevo, si existe
        if (!is_null($sNuevoTelefono) && !preg_match('/^\d+$/', $sNuevoTelefono)) {
            $errcode = 400; $errdesc = 'Bad request: invalid new phone';
            return FALSE;
        }

        // Revisar horarios
        if (is_array($horario)) {
            // Formatos correctos de fecha
            if (!isset($horario['date_init']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $horario['date_init'])) {
                $this->_log->output('ERR: al agendar llamada: fecha de inicio inválida, se espera YYYY-MM-DD');
                $errcode = 400; $errdesc = 'Bad request: invalid date_init';
                return FALSE;
            } elseif (!isset($horario['date_end']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $horario['date_end'])) {
                $this->_log->output('ERR: al agendar llamada: fecha de fin inválida, se espera YYYY-MM-DD');
                $errcode = 400; $errdesc = 'Bad request: invalid date_end';
                return FALSE;
            } elseif (!isset($horario['time_init']) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $horario['time_init'])) {
                $this->_log->output('ERR: al agendar llamada: hora de inicio inválida, se espera HH:MM:SS');
                $errcode = 400; $errdesc = 'Bad request: invalid time_init';
                return FALSE;
            } elseif (!isset($horario['time_end']) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $horario['time_end'])) {
                $this->_log->output('ERR: al agendar llamada: hora de fin inválida, se espera HH:MM:SS');
                $errcode = 400; $errdesc = 'Bad request: invalid time_end';
                return FALSE;
            }
            
            // Ordenamiento correcto
            if ($horario['date_init'] > $horario['date_end']) {
                $t = $horario['date_init'];
                $horario['date_init'] = $horario['date_end'];
                $horario['date_end'] = $t;
            }
            
            // Fecha debe estar en el futuro
            if ($horario['date_init'] < date('Y-m-d')) {
                $this->_log->output('ERR: al agendar llamada: fecha de inicio anterior a fecha actual');
                $errcode = 400; $errdesc = 'Bad request: date_init before current date';
                return FALSE;
            }
        } elseif (!is_null($horario)) {
            $this->_log->output('ERR: (internal) al agendar llamada: horario no es un arreglo');
            return FALSE;
        }

        // Información de la llamada atendida por el agente
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada)) {
            $errcode = 417; $errdesc = 'Not in outgoing call';
            return FALSE;
        }
        if ($infoLlamada['calltype'] != 'outgoing') {
            //$this->_log->output('ERR: al agendar llamada: no se puede agendar llamada entrante: '.$sAgente);
            $errcode = 417; $errdesc = 'Not in outgoing call';
            return FALSE;
        }
        
        // Leer toda la información de la campaña y la cola
        $sqlLlamadaCampania = <<<SQL_LLAMADA_CAMPANIA_AGENDAMIENTO
SELECT campaign.datetime_init, campaign.datetime_end, campaign.daytime_init, 
    campaign.daytime_end, calls.id_campaign, calls.phone
FROM campaign, calls
WHERE campaign.id = calls.id_campaign AND calls.id = ?
SQL_LLAMADA_CAMPANIA_AGENDAMIENTO;
        $recordset = $this->_db->prepare($sqlLlamadaCampania);
        $recordset->execute(array($infoLlamada['callid']));
        $tuplaCampania = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        
        // Validar que el rango de fecha y hora requerido es compatible con campaña
        if (is_array($horario)) {
            if (!($tuplaCampania['datetime_init'] <= $horario['date_init'] && 
                $horario['date_end'] <= $tuplaCampania['datetime_end'])) {
                $errcode = 417; $errdesc = 'Supplied date range outside campaign range';
                return FALSE;
            }
            if (!($tuplaCampania['daytime_init'] <= $horario['time_init'] &&
                $horario['time_end'] <= $tuplaCampania['daytime_end'])) {
                $errcode = 417; $errdesc = 'Supplied time range outside campaign range';
                return FALSE;
            }
        }

        // Acumular los parámetros de la nueva llamada por insertar
        // DEBEN PERMANECER EN ESTE ORDEN
        $paramNuevaLlamadaSQL = array(
            $tuplaCampania['id_campaign'],  // TODO: se puede mandar llamada a otra campaña...
            is_null($sNuevoTelefono) ? $tuplaCampania['phone'] : $sNuevoTelefono,
            is_null($horario) ? NULL : $horario['date_init'],
            is_null($horario) ? NULL : $horario['date_end'],
            is_null($horario) ? NULL : $horario['time_init'],
            is_null($horario) ? NULL : $horario['time_end'],            
        );

        // Leer los atributos a heredar de la llamada, para (opcionalmente) modificarlos
        $sqlLlamadaAtributos = <<<SQL_LLAMADA_ATRIBUTOS_AGENDAMIENTO
SELECT column_number, columna, value FROM call_attribute 
WHERE id_call = ?
ORDER BY column_number
SQL_LLAMADA_ATRIBUTOS_AGENDAMIENTO;
        $recordset = $this->_db->prepare($sqlLlamadaAtributos);
        $recordset->execute(array($infoLlamada['callid']));
        $attrLlamada = array();
        foreach ($recordset as $tupla) {
        	$attrLlamada[$tupla['column_number']] = array($tupla['columna'], $tupla['value']);
        }        
        if (!is_null($sNuevoNombre)) {
            // Columnas de propiedades se numeran desde 1
            if (!isset($attrLlamada[1])) $attrLlamada[1] = array('Campo1', $sNuevoNombre);
            $attrLlamada[1][1] = $sNuevoNombre;
        }

        // Validar que no exista una llamada por agendar al mismo número
        $sqlExistenciaLlamadaPrevia = <<<SQL_LLAMADA_PREVIA
SELECT COUNT(*) FROM calls 
WHERE id_campaign = ? AND phone = ? AND date_init = ? AND date_end = ? 
    AND time_init = ? AND time_end = ?
SQL_LLAMADA_PREVIA;
        $recordset = $this->_db->prepare($sqlExistenciaLlamadaPrevia);
        $recordset->execute($paramNuevaLlamadaSQL);
        $existe = $recordset->fetchColumn(0);
        $recordset->closeCursor();
        if ($existe > 0) {
            $errcode = 417; $errdesc = 'Found duplicate scheduled call';
            return FALSE;
        }
        
        try {
            // Inicio de transacción
            $this->_db->beginTransaction();
    
            // Agregar agente a agendar, si es necesario, e insertar
            $paramNuevaLlamadaSQL[] = $bMismoAgente ? $sAgente : NULL;
            $sqlInsertarLlamadaAgendada = <<<SQL_INSERTAR_AGENDAMIENTO
INSERT INTO calls (id_campaign, phone, date_init, date_end, time_init, time_end, agent)
VALUES (?, ?, ?, ?, ?, ?, ?)
SQL_INSERTAR_AGENDAMIENTO;
            $sth = $this->_db->prepare($sqlInsertarLlamadaAgendada);
            $sth->execute($paramNuevaLlamadaSQL);
            $idNuevaLlamada = $this->_db->lastInsertId();
            
            // Insertar atributos para la nueva llamada
            $sth = $this->_db->prepare(
                'INSERT INTO call_attribute (columna, value, column_number, id_call) '.
                'VALUES (?, ?, ?, ?)');
            foreach ($attrLlamada as $iColNum => $tuplaAttr) {
                // Se asume elemento 0 es 'columna', 1 es 'value' en call_attribute
                $tuplaAttr[] = $iColNum;        // Debería ser posición 2
                $tuplaAttr[] = $idNuevaLlamada; // Debería ser posición 3
                $sth->execute($tuplaAttr);
            }
            
            // Final de transacción
            $this->_db->commit();
            return TRUE;
        } catch (PDOException $e) {
            $this->_log->output('ERR: '.__METHOD__.
                ': no se puede realizar inserción de llamada agendada: '.
                implode(' - ', $e->errorInfo));
            $errcode = 500; $errdesc = 'Failed to insert scheduled call';
        	$this->_db->rollBack();
            return FALSE;
        }        
    }

    private function Request_transfercall($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presentes
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        // Verificar que número de extensión está presente
        if (!isset($comando->extension)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sExtension = (string)$comando->extension;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_transferResponse = $xml_response->addChild('transfercall_response');

        // Verificar que el agente sea válido en el sistema
        $listaAgentes = $this->_listarAgentes();
        if (!in_array($sAgente, array_keys($listaAgentes))) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        // Verificar el hash del agente
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        $sCanalRemoto = $infoSeguimiento['clientchannel'];
        if (is_null($sCanalRemoto)) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Obtener la información de la llamada atendida por el agente
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada) || is_null($infoLlamada['currentcallid'])) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Mandar a transferir la llamada usando el canal Agent/9000
        $r = $this->_ami->Redirect(
            $sCanalRemoto,      // channel 
            '',                 // extrachannel
            $sExtension,        // exten
            'from-internal',    // context
            1);                 // priority
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: '.__METHOD__.': al transferir llamada: no se puede transferir '.
                $sCanalRemoto.' a '.$sExtension.' - '.$r['Message']);
            $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'Unable to transfer call');
            return $xml_response;
        } else {
            $this->_registrarTransferencia($infoLlamada, $sExtension);
        }

        $xml_transferResponse->addChild('success');
        return $xml_response;
    }

    private function Request_atxfercall($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presentes
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        // Verificar que número de extensión está presente
        if (!isset($comando->extension)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sExtension = (string)$comando->extension;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_transferResponse = $xml_response->addChild('atxfercall_response');

        // Verificar que el agente sea válido en el sistema
        $listaAgentes = $this->_listarAgentes();
        if (!in_array($sAgente, array_keys($listaAgentes))) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        // Verificar el hash del agente
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        // El siguiente código asume formato Agent/9000
        $agentFields = $this->_parseAgent($sAgente);
        if (is_null($agentFields)) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        // Obtener la información de la llamada atendida por el agente
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada)) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        if (is_null($infoLlamada['agentchannel'])) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Mandar a transferir la llamada usando el canal Agent/9000
        $r = $this->_ami->Atxfer(
            $infoLlamada['agentchannel'],
            $sExtension.'#',    // exten
            'from-internal',    // context
            1);                 // priority
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: '.__METHOD__.': al transferir llamada: no se puede transferir '.
                $infoLlamada['agentchannel'].' a '.$sExtension.' - '.$r['Message']);
            $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'Unable to transfer call');
            return $xml_response;
        } else {
            $this->_registrarTransferencia($infoLlamada, $sExtension);
        }

        $xml_transferResponse->addChild('success');
        return $xml_response;
    }

    private function _registrarTransferencia($infoLlamada, $sExtension)
    {
    	$sth = $this->_db->prepare( 
            'UPDATE '.(($infoLlamada['calltype'] == 'incoming') ? 'call_entry' : 'calls').
            ' SET transfer = ? WHERE id = ?');
        $sth->execute(array($sExtension, $infoLlamada['callid']));
    }

    private function Request_hold($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presente
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_holdResponse = $xml_response->addChild('hold_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 417, 'Invalid agent number');
            return $xml_response;
        }

        // Verificar que el agente está autorizado a realizar operación
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        /* Verificar si existe una extensión de parqueo. Por omisión el FreePBX
         * de Elastix NO HABILITA soporte de extensión de parqueo */ 
        $sExtParqueo = $this->_leerConfigExtensionParqueo();
        if (is_null($sExtParqueo)) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 500, 'Parked call extension is disabled');
            return $xml_response;
        }

        // Obtener el ID del break que corresponde al hold
        $recordset = $this->_db->prepare('SELECT id FROM break WHERE tipo = "H" AND status = "A"');
        $recordset->execute();
        $idHold = $recordset->fetchColumn(0);
        $recordset->closeCursor();

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_holdResponse, 417, 'Agent currenty not logged in');
            return $xml_response;
        }
        $sCanalRemoto = $infoSeguimiento['clientchannel'];
        if (is_null($sCanalRemoto)) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Obtener la información de la llamada atendida por el agente
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada) || is_null($infoLlamada['currentcallid'])) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Si el agente ya estaba en hold, se debería validar si la llamada sigue activa
        $bHoldQuitado = FALSE;
        if (!is_null($infoSeguimiento['id_audit_hold'])) {
            $sExtLlamadaParqueada = $this->_buscarExtensionParqueo(
                $infoSeguimiento['clientchannel']);
            if (!is_null($sExtLlamadaParqueada)) {
                // La llamada sigue en hold. No se requiere hacer nada
                $xml_holdResponse->addChild('success');
                return $xml_response;
            } else {
                // El abonado se cansó de esperar y ha colgado. Se termina el
                // hold anterior y se marca en la base de datos.
                $iTimestampFinalPausa = time();
                $this->_tuberia->msg_AMIEventProcess_quitarHoldAgente($sAgente);
                $this->_eccpProcess->marcarFinalBreakAgente(
                    $infoSeguimiento['id_audit_hold'], $iTimestampFinalPausa);
                $bHoldQuitado = TRUE;
                
                // TODO: evento OnHangup debería revisar info_hold de todos los
                // agentes para eliminar los holds y pausas de las llamadas que
                // han colgado.
            }
        }

        // En este punto, $infoLlamada tiene la información para iniciar hold

        // Ejecutar la pausa a través del AMI. 
        /* TODO: puede haber una carrera si dos o más conexiones intentan hacer
         * que el mismo agente entre en break al mismo tiempo.
         */
        if ($infoSeguimiento['num_pausas'] == 0) {
            $r = $this->_ami->QueuePause(NULL, $sAgente, 'true');
            if ($r['Response'] != 'Success') {
                $this->_log->output('ERR: '.__METHOD__.' (internal) no se puede poner al agente en pausa: '.
                    $sAgente.' - '.$r['Message']);
                
                $this->_agregarRespuestaFallo($xml_holdResponse, 500, 'Unable to start agent hold');
                return $xml_response;
            }
        }
        $iTimestampInicioPausa = time();
        
        // Mandar a escribir el inicio de la pausa a la base de datos
        $idAuditHold = $this->_eccpProcess->marcarInicioBreakAgente(
            $infoSeguimiento['id_agent'], $idHold, $iTimestampInicioPausa);
        if (is_null($idAuditHold)) {
            if ($infoSeguimiento['num_pausas'] == 0) {
                $r = $this->_ami->QueuePause(NULL, $sAgente, 'false');
            }
            $this->_agregarRespuestaFallo($xml_holdResponse, 500, 'Unable to start agent hold');
            return $xml_response;
        }
        
        // Notificar éxito en inicio de break
        $this->_tuberia->msg_AMIEventProcess_idNuevoHoldAgente($sAgente, $idHold, $idAuditHold);
        
        // Marcar en calls y current_calls el estado de hold
        try {
        	$this->_db->beginTransaction();
            if ($infoLlamada['calltype'] == 'incoming') {
                $sth = $this->_db->prepare(
                    'UPDATE current_call_entry SET hold = ? WHERE id = ?');
                $sth->execute(array('S', $infoLlamada['currentcallid']));
                $sth = $this->_db->prepare('UPDATE call_entry set status = ? WHERE id = ?');
                $sth->execute(array('hold', $infoLlamada['callid']));
            } else {
                $sth = $this->_db->prepare(
                    'UPDATE current_calls SET hold = ? WHERE id = ?');
                $sth->execute(array('S', $infoLlamada['currentcallid']));
                $sth = $this->_db->prepare('UPDATE calls set status = ? WHERE id = ?');
                $sth->execute(array('OnHold', $infoLlamada['callid']));
            }
            
            // Notificar progreso de la llamada
            $paramProgreso = array(
                'datetime_entry'    =>  date('Y-m-d H:i:s', $iTimestampInicioPausa),
                'new_status'        =>  'OnHold',
            );
            if ($infoLlamada['calltype'] == 'incoming') {
                $paramProgreso['id_campaign_incoming'] = $infoLlamada['campaign_id'];
                $paramProgreso['id_call_incoming'] = $infoLlamada['callid'];
            } else {
                $paramProgreso['id_campaign_outgoing'] = $infoLlamada['campaign_id'];
                $paramProgreso['id_call_outgoing'] = $infoLlamada['callid'];
            }
            $this->_eccpProcess->notificarProgresoLlamada($paramProgreso);
            
            $this->_db->commit();
        } catch (PDOException $e) {
        	$this->_db->rollBack();
            $this->_log->output('ERR: '.__METHOD__. ": no se puede actualizar estado de hold en DB: ".
                implode(' - ', $e->errorInfo));
        }
        
        // Ejecutar realmente la redirección al hold
        $r = $this->_ami->Redirect(
            $infoSeguimiento['clientchannel'], // channel 
            '',                             // extrachannel
            $sExtParqueo,                   // exten
            'from-internal',                // context
            1);                             // priority
        if ($r['Response'] != 'Success') {
            $this->_log->output(
                "ERR: ".__METHOD__."al iniciar hold: no se puede ejecutar hold ".
                "(Redirect {$infoSeguimiento['clientchannel']} --> {$sExtParqueo}) - {$r['Message']}");
            $this->_tuberia->msg_AMIEventProcess_quitarHoldAgente($sAgente);
            $this->_eccpProcess->marcarFinalBreakAgente(
                $infoSeguimiento['id_audit_hold'], $iTimestampInicioPausa);
            return FALSE;
        }

        // Notificar a ECCP el inicio de la pausa de hold
        $this->multiplexSrv->notificarEvento_PauseStart($sAgente, array(
            'pause_class'   =>  'hold',
            'pause_start'   =>  date('Y-m-d H:i:s', $iTimestampInicioPausa),
        ));

        $xml_holdResponse->addChild('success');
        return $xml_response;
    }

    private function Request_unhold($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presente
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_unholdResponse = $xml_response->addChild('unhold_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 417, 'Invalid agent number');
            return $xml_response;
        }

        // Verificar que el agente está autorizado a realizar operación
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        /* Verificar si existe una extensión de parqueo. Por omisión el FreePBX
         * de Elastix NO HABILITA soporte de extensión de parqueo */ 
        $sExtParqueo = $this->_leerConfigExtensionParqueo();
        if (is_null($sExtParqueo)) {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 500, 'Parked call extension is disabled');
            return $xml_response;
        }

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 417, 'Agent currenty not logged in');
            return $xml_response;
        }
        $sCanalRemoto = $infoSeguimiento['clientchannel'];
        if (is_null($sCanalRemoto)) {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Obtener la información de la llamada atendida por el agente
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada) || is_null($infoLlamada['currentcallid'])) {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 417, 'Agent not in call');
            return $xml_response;
        }
        
        // Si el agente no estaba en hold, se devuelve éxito sin hacer nada más
        if (is_null($infoSeguimiento['id_audit_hold'])) {
            $xml_unholdResponse->addChild('success');
            return $xml_response;
        }

        $sExtLlamadaParqueada = $this->_buscarExtensionParqueo($infoSeguimiento['clientchannel']);
        if (!is_null($sExtLlamadaParqueada)) {
            $sActionID = 'ECCP:1.0:'.posix_getpid().':RedirectFromHold';
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: intentando recuperar llamada:\n".
                    "\tChannel      =>  $sAgente\n".
                    "\tExten        =>  $sExtLlamadaParqueada\n".
                    "\tContext      =>  from-internal\n".
                    "\tActionID     =>  $sActionID");
            }
            
            // Sacar la llamada del parqueo y redirigirla al agente pausado
            $r = $this->_ami->Originate(
                $sAgente,               // channel
                $sExtLlamadaParqueada,  // extension
                'from-internal',        // context
                '1',                    // priority
                NULL, NULL, NULL, NULL, NULL, NULL,
                TRUE,                   // async
                $sActionID
                );
            if ($r['Response'] != 'Success') {
                $this->_log->output('ERR: al terminar hold: no se puede retomar llamada - '.$r['Message']);
            }
            if ($this->DEBUG) {
                $this->_log->output('DEBUG: Originate para recuperar llamada devuelve: '.print_r($r, 1));
            }
        }

        // Se delega registro de final de HOLD a manejadores de eventos

        $xml_unholdResponse->addChild('success');
        return $xml_response;
    }

    /* Leer el estado de /etc/asterisk/features_general_additional.conf y 
     * obtener la extensión de parqueo configurada. Devuelve NULL en caso de 
     * error o si la  característica de extensión de parqueo no está 
     * configurada, o la extensión numérica en caso contrario. */
    private function _leerConfigExtensionParqueo()
    {
        $sNombreArchivo = '/etc/asterisk/features_general_additional.conf';
        if (!file_exists($sNombreArchivo)) {
            $this->_log->output("WARN: $sNombreArchivo no se encuentra.");
            return NULL;
        }
        if (!is_readable($sNombreArchivo)) {
            $this->_log->output("WARN: $sNombreArchivo no puede leerse por usuario de marcador.");
            return NULL;            
        }
        $infoConfig = parse_ini_file($sNombreArchivo, TRUE);
        if (is_array($infoConfig)) {
            $sExtensionParqueo = isset($infoConfig['parkext']) ? $infoConfig['parkext'] : '';
            return (preg_match('/^\d+$/', $sExtensionParqueo)) ? $sExtensionParqueo : NULL;
        } else {
            $this->_log->output("ERR: $sNombreArchivo no puede parsearse correctamente.");          
        }
        return NULL;
    }

    /* Ejecutar el comando adecuado según la versión de Asterisk para listar las
     * extensiones de llamadas parqueadas. Se devuelve el número de extensión 
     * que contiene el canal que se ha pasado como parámetro, o NULL si ha 
     * ocurrido un problema o si no se encuentra el canal. */
    private function _buscarExtensionParqueo($sCanal)
    {
        $versionMinima = array(1, 6, 0);
        $asteriskVersion = $this->_eccpProcess->getAsteriskVersion();
        while (count($versionMinima) < count($asteriskVersion))
            array_push($versionMinima, 0);
        while (count($versionMinima) > count($asteriskVersion))
            array_push($asteriskVersion, 0);
        $sComandoParqueo = ($asteriskVersion >= $versionMinima) 
            ? 'parkedcalls show' 
            : 'show parkedcalls';
        $r = $this->_ami->Command($sComandoParqueo);
        if (!isset($r['data'])) return NULL;

/*
Privilege: Command
 Num                   Channel (Context         Extension    Pri ) Timeout 
*** Parking lot: default
71                 SIP/1065-00000014 (from-internal   s            1   )     38s
---
1 parked call in total.
 */
        $lineas = explode("\n", $r['data']); $regs = NULL;
        foreach ($lineas as $sLinea) {
            if (preg_match('/^\s*(\d{2,})\s*(\S+)/', $sLinea, $regs)) {
                if ($regs[2] == $sCanal) return $regs[1];
            }
        }
        return NULL;
    }

    private function Request_getagentqueues($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente está presente
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getagentqueuesResponse = $xml_response->addChild('getagentqueues_response');

        // El siguiente código asume formato Agent/9000
        $agentFields = $this->_parseAgent($sAgente);
        if ($sAgente == 'any') {
            $sAgente = NULL;
        } elseif (is_null($agentFields)) {
            $this->_agregarRespuestaFallo($xml_getagentqueuesResponse, 417, 'Invalid agent number');
            return $xml_response;
        }

        // Verificar que la extensión y el agente son válidos en el sistema
        $listaAgentes = $this->_listarAgentes();
        if (!in_array($sAgente, array_keys($listaAgentes))) {
            $this->_agregarRespuestaFallo($xml_getagentqueuesResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        
        $listaColas = $this->_listarColasAgente($sAgente);
        $xml_agentQueues = $xml_getagentqueuesResponse->addChild('queues');

        // Reportar también las colas a las que está suscrito el agente
        if (is_array($listaColas)) foreach ($listaColas as $sCola) {
            $xml_agentQueues->addChild('queue', str_replace('&', '&amp;', $sCola));
        }

        // Agregar además las colas a las cuales va a suscribirse el agente dinámico
        $listaColasDyn = $this->_getQueuesGivenDynamicMember($agentFields['type'], $agentFields['number']);
        if (is_array($listaColasDyn)) foreach ($listaColasDyn as $sCola) {
            if (!in_array($sCola, $listaColas))
                $xml_agentQueues->addChild('queue', str_replace('&', '&amp;', $sCola));
        }

        return $xml_response;
    }

    private function _listarColasAgente($sAgente)
    {
        /* Por ahora se asume que $sAgente es de la forma Agent/9000 y que 
         * aparece de esta misma manera en el reporte de "queue show" 
         *  5000 has 0 calls (max unlimited) in 'ringall' strategy (0s holdtime, 0s talktime), W:0, C:0, A:0, SL:0.0% within 60s
         *     No Members
         *     No Callers
         *  
         *  8001 has 0 calls (max unlimited) in 'ringall' strategy (0s holdtime, 0s talktime), W:0, C:0, A:0, SL:0.0% within 60s
         *     Members: 
         *        Agent/9000 (Unavailable) has taken no calls yet
         *     No Callers
         *  
         *  8000 has 0 calls (max unlimited) in 'ringall' strategy (0s holdtime, 0s talktime), W:0, C:0, A:0, SL:0.0% within 60s
         *     Members: 
         *        Agent/9000 (Unavailable) has taken no calls yet
         *     No Callers
         *
         */
        $respuestaCola = $this->_ami->Command('queue show');
        if (isset($respuestaCola['data'])) {
            $listaColas = array();
            $lineasRespuesta = explode("\n", $respuestaCola['data']);
            $sColaActual = NULL;
            foreach ($lineasRespuesta as $sLinea) {
                $regs = NULL;
                if (preg_match('/^(\d+) has \d+ calls/', $sLinea, $regs)) {
                   // Se ha encontrado el inicio de una descripción de cola
                    $sColaActual = $regs[1];
                } elseif (preg_match('|^\s+(\w+/\d+)|', $sLinea, $regs)) {
                    if (!is_null($sColaActual) && $sAgente == $regs[1]) {
                        // Se ha encontrado el agente en una cola en particular
                        $listaColas[] = $sColaActual;
                    }
                }
            }
            return $listaColas;
        } else {
            $this->_log->output('ERR: lost synch with Asterisk AMI ("queue show" response lacks "data").');
            return NULL;
        }
    }

    private function _listarAgentesLogoneadosCola($sCola)
    {
    	$respuestaCola = $this->_ami->Command('queue show '.$sCola);
        if (isset($respuestaCola['data'])) {
            $listaAgentes = array();
            $lineasRespuesta = explode("\n", $respuestaCola['data']);
            $bSeccionMiembros = FALSE;
            foreach ($lineasRespuesta as $sLinea) {
                $regs = NULL;
                if (strpos($sLinea, 'Members:') !== FALSE)
                    $bSeccionMiembros = TRUE;
                elseif (strpos($sLinea, 'Callers') !== FALSE)
                    $bSeccionMiembros = FALSE;
                elseif (preg_match('|^\s+(\w+/\d+)\s+|', $sLinea, $regs)) {
                	$listaAgentes[] = $regs[1];
                }
            }
            return $listaAgentes;
        } else {
            $this->_log->output('ERR: lost synch with Asterisk AMI ("queue show" response lacks "data").');
            return NULL;
        }
    }

    private function _listarAgentesDinamicosCola($sCola)
    {
        $respuestaCola = $this->_ami->Command('database show QPENALTY/'.$sCola.'/agents');
        if (isset($respuestaCola['data'])) {
            $listaAgentes = array();
            $lineasRespuesta = explode("\n", $respuestaCola['data']);
            foreach ($lineasRespuesta as $sLinea) {
                $regs = NULL;
                if (preg_match('|/QPENALTY/\d+/agents/(\w)(\d+)|', $sLinea, $regs)) {
                	switch ($regs[1]) {
                	case 'I':  $listaAgentes[] = 'IAX2/'.$regs[2]; break;
                    case 'S':  $listaAgentes[] = 'SIP/'.$regs[2]; break;
                	}
                }
            }
            return $listaAgentes;
        } else {
            $this->_log->output('ERR: lost synch with Asterisk AMI ("queue show" response lacks "data").');
            return NULL;
        }
    }

    private function Request_getagentactivitysummary($comando)
    {
        // Fechas de inicio y fin
        $sFechaInicio = $sFechaFin = date('Y-m-d');
        if (isset($comando->datetime_start)) {
            $sFechaInicio = (string)$comando->datetime_start;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaInicio))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid start date');
        }
        if (isset($comando->datetime_end)) {
            $sFechaFin = (string)$comando->datetime_end;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaFin))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid end date');
        }
        if (!is_null($sFechaInicio) && !is_null($sFechaFin) && $sFechaFin < $sFechaInicio) {
            $t = $sFechaInicio;
            $sFechaInicio = $sFechaFin;
            $sFechaFin = $t;
        }
        
        $xml_response = new SimpleXMLElement('<response />');
        $xml_getagentactivitysummaryResponse = $xml_response->addChild('getagentactivitysummary_response');

        // Leer la información de los agentes conocidos y su historial de sesión
        $sPeticionSQL = <<<LEER_AGENTE_AUDIT
SELECT agent.id, agent.type, agent.number, agent.name, SUM(TIME_TO_SEC(duration)) AS total_login_time
FROM agent
LEFT JOIN audit 
    ON agent.id = audit.id_agent AND audit.id_break IS NULL 
    AND audit.datetime_init BETWEEN ? AND ?
WHERE estatus = 'A' GROUP BY agent.id
LEER_AGENTE_AUDIT;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59'));
        $listaAgentes = $recordset->fetchAll(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        
        // Construir el árbol de salida, y consultar el historial de atención de llamadas
        $xml_agents = $xml_getagentactivitysummaryResponse->addChild('agents');
        $sPeticionSQL_sumallamadas = <<<LEER_HISTORIAL_ATENCION
(SELECT 'incoming' AS campaign_type, queue_call_entry.queue AS queue, 
    SUM(call_entry.duration) AS sec_calls, COUNT(*) AS num_calls
FROM call_entry, queue_call_entry
WHERE call_entry.id_agent = ?
    AND call_entry.id_queue_call_entry = queue_call_entry.id
    AND call_entry.datetime_init BETWEEN ? AND ?
GROUP BY queue_call_entry.queue)
UNION
(SELECT 'outgoing' AS campaign_type, campaign.queue, 
    SUM(calls.duration) AS sec_calls, COUNT(*) AS num_calls
FROM calls, campaign
WHERE calls.id_agent = ?
    AND calls.id_campaign = campaign.id
    AND calls.start_time BETWEEN ? AND ?
GROUP BY campaign.queue)
LEER_HISTORIAL_ATENCION;
        $recordset_sumallamadas = $this->_db->prepare($sPeticionSQL_sumallamadas);
        $sPeticionSQL_ultimasesion = <<<LEER_ULTIMA_SESION
SELECT datetime_init, datetime_end FROM audit
WHERE id_agent = ? AND id_break IS NULL AND datetime_init BETWEEN ? AND ?
ORDER BY datetime_init DESC LIMIT 0,1
LEER_ULTIMA_SESION;
        $recordset_ultimasesion = $this->_db->prepare($sPeticionSQL_ultimasesion);
        $sPeticionSQL_ultimapausa = <<<LEER_ULTIMA_PAUSA
SELECT datetime_init, datetime_end FROM audit
WHERE id_agent = ? AND id_break IS NOT NULL AND datetime_init BETWEEN ? AND ?
ORDER BY datetime_init DESC LIMIT 0,1
LEER_ULTIMA_PAUSA;
        $recordset_ultimapausa = $this->_db->prepare($sPeticionSQL_ultimapausa);
        foreach ($listaAgentes as $infoAgente) {
        	$xml_agent = $xml_agents->addChild('agent');
            $xml_agent->addChild('agentchannel', $infoAgente['type'].'/'.$infoAgente['number']);
            $xml_agent->addChild('agentname', str_replace('&', '&amp;', $infoAgente['name']));
            $xml_agent->addChild('logintime', is_null($infoAgente['total_login_time']) ? 0 : $infoAgente['total_login_time']);

            $recordset_sumallamadas->execute(array(
                $infoAgente['id'], $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59',
                $infoAgente['id'], $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59',));
            $listaResumen = array('incoming' => array(), 'outgoing' => array());
            foreach ($recordset_sumallamadas->fetchAll(PDO::FETCH_ASSOC) as $tupla) {
            	$listaResumen[$tupla['campaign_type']][] = $tupla;
            }
            
            $xml_callsummary = $xml_agent->addChild('callsummary');
            foreach (array('incoming', 'outgoing') as $k) {
            	if (!isset($listaResumen[$k])) $listaResumen[$k] = array();
                $xml_campaigntype = $xml_callsummary->addChild($k);
                foreach ($listaResumen[$k] as $queuesummary) {
                	$xml_queue = $xml_campaigntype->addChild('queue');
                    $xml_queue->addAttribute('id', (string)$queuesummary['queue']);
                    $xml_queue->addChild('sec_calls', $queuesummary['sec_calls']);
                    $xml_queue->addChild('num_calls', $queuesummary['num_calls']);
                }
            }
            
            // Información sobre inicio y final de sesión más reciente del agente
            $recordset_ultimasesion->execute(array(
                $infoAgente['id'], $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59'));
            $tupla = $recordset_ultimasesion->fetch(PDO::FETCH_ASSOC);
            $recordset_ultimasesion->closeCursor();
            if ($tupla) {
            	$xml_agent->addChild('lastsessionstart', $tupla['datetime_init']);
                if (!is_null($tupla['datetime_end']))
                    $xml_agent->addChild('lastsessionend', $tupla['datetime_end']);
            }

            // Información sobre inicio y final de pausa más reciente del agente
            $recordset_ultimapausa->execute(array(
                $infoAgente['id'], $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59'));
            $tupla = $recordset_ultimapausa->fetch(PDO::FETCH_ASSOC);
            $recordset_ultimapausa->closeCursor();
            if ($tupla) {
                $xml_agent->addChild('lastpausestart', $tupla['datetime_init']);
                if (!is_null($tupla['datetime_end']))
                    $xml_agent->addChild('lastpauseend', $tupla['datetime_end']);
            }
        }
        return $xml_response;
    }

    private function Request_getchanvars($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        // Verificar que agente está presente
        if (!isset($comando->agent_number)) 
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getchanvarsResponse = $xml_response->addChild('getchanvars_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_getchanvarsResponse, 417, 'Invalid agent number');
            return $xml_response;
        }

        // Verificar que el agente está autorizado a realizar operación
        if (!$this->_hashValidoAgenteECCP($comando)) {
            $this->_agregarRespuestaFallo($xml_getchanvarsResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_getchanvarsResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_getchanvarsResponse, 417, 'Agent currenty not logged in');
            return $xml_response;
        }
        $sCanalRemoto = $infoSeguimiento['clientchannel'];
        if (is_null($sCanalRemoto)) {
            $this->_agregarRespuestaFallo($xml_getchanvarsResponse, 417, 'Agent not in call');
            return $xml_response;
        }
        $xml_getchanvarsResponse->addChild('clientchannel', str_replace('&', '&amp;', $sCanalRemoto));
        $xml_chanvars = $xml_getchanvarsResponse->addChild('chanvars');

        // Listar la información disponible sobre las variables de canal
        $respuesta = $this->_ami->Command('core show channel '.$sCanalRemoto);
        if (isset($respuesta['data'])) {
        	$bSeccionVars = FALSE;
            foreach (explode("\n", $respuesta['data']) as $sLinea) {
            	$regs = NULL;
                if (preg_match('/^\s+Variables:\s*$/', $sLinea)) {
                    $bSeccionVars = TRUE;
                } elseif ($bSeccionVars && preg_match('/^(\w+)=(.*)$/', $sLinea, $regs)) {
                	$xml_chanvar = $xml_chanvars->addChild('chanvar');
                    $xml_chanvar->addChild('label', str_replace('&', '&amp;', $regs[1]));
                    $xml_chanvar->addChild('value', str_replace('&', '&amp;', $regs[2]));
                } elseif (trim($sLinea) == '') {
                	$bSeccionVars = FALSE;
                }
            }
        } else {
            $this->_log->output('ERR: lost synch with Asterisk AMI ("core show channel" response lacks "data").');
            return $this->_generarRespuestaFallo(500, 'No AMI connection');
        }
        return $xml_response;
    }

    private function Request_callprogress($comando)
    {
        if (is_null($this->_sUsuarioECCP))
            return $this->_generarRespuestaFallo(401, 'Unauthorized');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_callprogress = $xml_response->addChild('callprogress_response');

        $this->_bProgresoLlamada = ((int)$comando->enable != 0);
        $xml_callprogress->addChild('success');
        return $xml_response;
    }

    /***************************** EVENTOS *****************************/
    
    function notificarEvento_AgentLogin($sAgente, $bExitoLogin)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLoggedIn = $bExitoLogin 
            ? $xml_response->addChild('agentloggedin')
            : $xml_response->addChild('agentfailedlogin');
        $xml_agentLoggedIn->addChild('agent', str_replace('&', '&amp;', $sAgente));

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_AgentLogoff($sAgente)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLoggedIn = $xml_response->addChild('agentloggedout');
        $xml_agentLoggedIn->addChild('agent', str_replace('&', '&amp;', $sAgente));

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }
    
    function notificarEvento_AgentLinked($sAgente, $sRemChannel, $infoLlamada)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLinked = $xml_response->addChild('agentlinked');
        $infoLlamada['agent_number'] = $sAgente;
        $infoLlamada['remote_channel'] = $sRemChannel;
        $this->_construirRespuestaCallInfo($infoLlamada, $xml_agentLinked);
        
        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }
    
    function notificarEvento_AgentUnlinked($sAgente, $infoLlamada)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLinked = $xml_response->addChild('agentunlinked');
        $infoLlamada['agent_number'] = $sAgente;
        foreach ($infoLlamada as $sKey => $valor) {
            if (!is_null($valor)) $xml_agentLinked->addChild($sKey, str_replace('&', '&amp;', $valor));
        }
        
        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }
    
    function notificarEvento_PauseStart($sAgente, $infoPausa)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLinked = $xml_response->addChild('pausestart');
        $infoPausa['agent_number'] = $sAgente;
        foreach ($infoPausa as $sKey => $valor) {
            if (!is_null($valor)) $xml_agentLinked->addChild($sKey, str_replace('&', '&amp;', $valor));
        }
        
        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }
    
    function notificarEvento_PauseEnd($sAgente, $infoPausa)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLinked = $xml_response->addChild('pauseend');
        $infoPausa['agent_number'] = $sAgente;
        foreach ($infoPausa as $sKey => $valor) {
            if (!is_null($valor)) $xml_agentLinked->addChild($sKey, str_replace('&', '&amp;', $valor));
        }
        
        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }
    
    function notificarEvento_CallProgress($infoProgreso)
    {
    	if (is_null($this->_sUsuarioECCP)) return;
        if (!$this->_bProgresoLlamada) return;
        
        $xml_response = new SimpleXMLElement('<event />');
        $xml_callProgress = $xml_response->addChild('callprogress');
        foreach ($infoProgreso as $sKey => $valor) {
            if (!is_null($valor)) $xml_callProgress->addChild($sKey, str_replace('&', '&amp;', $valor));
        }
        
        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }
}
?>