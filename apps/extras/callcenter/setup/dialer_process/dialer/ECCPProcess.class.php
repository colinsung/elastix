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

class ECCPProcess extends TuberiaProcess
{
    private $DEBUG = FALSE; // VERDADERO si se activa la depuración

    private $_log;      // Log abierto por framework de demonio
    private $_dsn;      // Cadena que representa el DSN, estilo PDO
    private $_db;       // Conexión a la base de datos, PDO
    private $_ami = NULL;       // Conexión AMI a Asterisk
    private $_configDB; // Objeto de configuración desde la base de datos

    // Contadores para actividades ejecutadas regularmente
    private $_iTimestampUltimaRevisionConfig = 0;       // Última revisión de configuración

    // Estimación de la versión de Asterisk que se usa
    private $_asteriskVersion = array(1, 4, 0, 0);

    /* Si se pone a VERDADERO, el programa intenta finalizar y no deben 
     * aceptarse conexiones nuevas. Todas las conexiones existentes serán 
     * desconectadas. */
    private $_finalizandoPrograma = FALSE;

    private $_iTimestampInicioProceso;

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
    	$this->_log = $oMainLog;
        $this->_multiplex = new ECCPServer('tcp://0.0.0.0:20005', $this->_log, $this->_tuberia);
        $this->_multiplex->setProcess($this);
        $this->_tuberia->registrarMultiplexHijo($this->_multiplex);
        $this->_tuberia->setLog($this->_log);
        $this->_iTimestampInicioProceso = time();

        // Interpretar la configuración del demonio
        $this->_dsn = $this->_interpretarConfiguracion($infoConfig);
        if (!$this->_iniciarConexionDB()) return FALSE;
        $this->_multiplex->setDBConn($this->_db);
        
        // Leer el resto de la configuración desde la base de datos
        try {
            $this->_configDB = new ConfigDB($this->_db, $this->_log);
        } catch (PDOException $e) {
            $this->_log->output("FATAL: no se puede leer configuración DB - ".$e->getMessage());
        	return FALSE;
        }

        // Iniciar la conexión Asterisk
        if (!$this->_iniciarConexionAMI()) return FALSE;
        
        // Registro de manejadores de eventos
        foreach (array('notificarProgresoLlamada') as $k)
            $this->_tuberia->registrarManejador('CampaignProcess', $k, array($this, "msg_$k"));
        foreach (array('AgentLogin', 'AgentLogoff', 'AgentLinked',
            'AgentUnlinked', 'marcarFinalHold', 'notificarProgresoLlamada') as $k)
            $this->_tuberia->registrarManejador('AMIEventProcess', $k, array($this, "msg_$k"));

        // Registro de manejadores de eventos desde HubProcess
        $this->_tuberia->registrarManejador('HubProcess', 'finalizando', array($this, "msg_finalizando"));

        $this->DEBUG = $this->_configDB->dialer_debug;

        // Se ha tenido éxito si se están escuchando conexiones
        $this->_multiplex->setDEBUG($this->_configDB->dialer_debug);
        return $this->_multiplex->escuchaActiva();
    }
    
    private function _interpretarConfiguracion($infoConfig)
    {
        $dbHost = 'localhost';
        $dbUser = 'asterisk';
        $dbPass = 'asterisk';
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbhost'])) {
            $dbHost = $infoConfig['database']['dbhost'];
            $this->_log->output('Usando host de base de datos: '.$dbHost);
        } else {
            $this->_log->output('Usando host (por omisión) de base de datos: '.$dbHost);
        }
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbuser']))
            $dbUser = $infoConfig['database']['dbuser'];
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbpass']))
            $dbPass = $infoConfig['database']['dbpass'];

        return array("mysql:host=$dbHost;dbname=call_center", $dbUser, $dbPass);
    }

    private function _iniciarConexionDB()
    {
        try {
            $this->_db = new PDO($this->_dsn[0], $this->_dsn[1], $this->_dsn[2]);
            $this->_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return TRUE;
        } catch (PDOException $e) {
            $this->_db = NULL;
            $this->_log->output("FATAL: no se puede conectar a DB - ".$e->getMessage());
            return FALSE;
        }
    }

    public function procedimientoDemonio()
    {
        // Verificar posible desconexión de la base de datos
        if (!$this->_multiplex->dbValido()) {
        	$this->_db = NULL;
        }
        if (is_null($this->_db)) {
            $this->_log->output('INFO: intentando volver a abrir conexión a DB...');
            if (!$this->_iniciarConexionDB()) {
            	$this->_log->output('ERR: no se puede restaurar conexión a DB, se espera...');
                usleep(5000000);
            } else {
            	$this->_log->output('INFO: conexión a DB restaurada, se reinicia operación normal.');
                $this->_configDB->setDBConn($this->_db);
                $this->_multiplex->setDBConn($this->_db);
            }
        }

        // Verificar si la conexión AMI sigue siendo válida
        if (!is_null($this->_ami) && is_null($this->_ami->sKey)) {
            $this->_ami = NULL;
            $this->_multiplex->setAstConn(NULL);
        }
        if (is_null($this->_ami) && !$this->_finalizandoPrograma) {
            if (!$this->_iniciarConexionAMI()) {
                $this->_log->output('ERR: no se puede restaurar conexión a Asterisk, se espera...');
                if (!is_null($this->_db)) {
                    if ($this->_multiplex->procesarPaquetes())
                        $this->_multiplex->procesarActividad(0);
                    else $this->_multiplex->procesarActividad(5);
                } else {
                    usleep(5000000);
                }
            } else {
                $this->_log->output('INFO: conexión a Asterisk restaurada, se reinicia operación normal.');
            }
        }

        if (!is_null($this->_db) && !is_null($this->_ami) && !$this->_finalizandoPrograma) {
            try {
                $this->_verificarCambioConfiguracion();
            } catch (PDOException $e) {
                $this->_stdManejoExcepcionDB($e, 'no se puede verificar cambio en configuración');
            }
        }

        // Rutear los mensajes si hay DB
        if (!is_null($this->_db)) {
            // Rutear todos los mensajes pendientes entre tareas y agentes
            if ($this->_multiplex->procesarPaquetes())
                $this->_multiplex->procesarActividad(0);
            else $this->_multiplex->procesarActividad(1);
        }
        
    	return TRUE;
    }
    
    public function limpiezaDemonio($signum)
    {
    	
        // Mandar a cerrar todas las conexiones activas
        $this->_multiplex->finalizarServidor();

        // Desconectarse de la base de datos
        $this->_configDB = NULL;
        if (!is_null($this->_db)) {
            $this->_log->output('INFO: desconectando de la base de datos...');
            $this->_db = NULL;
        }
    }

    /**************************************************************************/

    private function _iniciarConexionAMI()
    {
        if (!is_null($this->_ami)) {
            $this->_log->output('INFO: Desconectando de sesión previa de Asterisk...');
            $this->_ami->disconnect();
            $this->_ami = NULL;
            $this->_multiplex->setAstConn(NULL);
        }
        $astman = new AMIClientConn($this->_multiplex, $this->_log);
        //$this->_momentoUltimaConnAsterisk = time();

        $this->_log->output('INFO: Iniciando sesión de control de Asterisk...');
        if (!$astman->connect(
                $this->_configDB->asterisk_asthost, 
                $this->_configDB->asterisk_astuser,
                $this->_configDB->asterisk_astpass)) {
            $this->_log->output("FATAL: no se puede conectar a Asterisk Manager");
            return FALSE;
        } else {
            // Averiguar la versión de Asterisk que se usa
            $this->_asteriskVersion = array(1, 4, 0, 0);
            $r = $astman->CoreSettings(); // Sólo disponible en Asterisk >= 1.6.0
            if ($r['Response'] == 'Success' && isset($r['AsteriskVersion'])) {
                $this->_asteriskVersion = explode('.', $r['AsteriskVersion']);
                $this->_log->output("INFO: CoreSettings reporta Asterisk ".implode('.', $this->_asteriskVersion));
            } else {
                $this->_log->output("INFO: no hay soporte CoreSettings en Asterisk Manager, se asume Asterisk 1.4.x.");
            }

            // ECCPProcess no tiene manejadores de eventos AMI

            $this->_multiplex->setAstConn($astman);
            $this->_ami = $astman;
            return TRUE;
        }
    }

    private function _verificarCambioConfiguracion()
    {
        $iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampUltimaRevisionConfig > 3) {
            $this->_configDB->leerConfiguracionDesdeDB();
            $listaVarCambiadas = $this->_configDB->listaVarCambiadas();
            if (count($listaVarCambiadas) > 0) {
                if (in_array('dialer_debug', $listaVarCambiadas)) {
                    $this->DEBUG = $this->_configDB->dialer_debug;
                    $this->_multiplex->setDEBUG($this->_configDB->dialer_debug);
                }
                $this->_configDB->limpiarCambios();
            }
            $this->_iTimestampUltimaRevisionConfig = $iTimestamp;
        }
    }
    
    private function _stdManejoExcepcionDB($e, $s)
    {
        $this->_log->output('ERR: '.__METHOD__. ": $s: ".implode(' - ', $e->errorInfo));
        $this->_log->output("ERR: traza de pila: \n".$e->getTraceAsString());
        if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 2006) {
            // Códigos correspondientes a pérdida de conexión de base de datos
            $this->_log->output('WARN: '.__METHOD__.
                ': conexión a DB parece ser inválida, se cierra...');
            $this->_db = NULL;
            $this->_multiplex->setDBConn(NULL);
        }
    }
    
    /**
     * Método para marcar en las tablas de auditoría que el agente ha iniciado
     * la sesión. Esta implementación verifica si el agente ya ha sido marcado
     * previamente como que inició la sesión, y sólo marca el inicio si no está
     * ya marcado antes.
     *
     * @param   string  $sAgente    Canal del agente que se verifica sesión
     * @param   int     $id_agent   ID en base de datos del agente
     * @param   float   $iTimestampLogin timestamp devuelto por microtime() de login
     *
     * @return  mixed   NULL en error, o el ID de la auditoría de inicio de sesión
     */
    private function _marcarInicioSesionAgente($idAgente, $iTimestampLogin)
    {
        try {
            // Verificación de sesión activa
            $sPeticionExiste = <<<SQL_EXISTE_AUDIT
SELECT id FROM audit
WHERE id_agent = ? AND datetime_init >= ? AND datetime_end IS NULL 
    AND duration IS NULL AND id_break IS NULL
ORDER BY datetime_init DESC
SQL_EXISTE_AUDIT;
            $recordset = $this->_db->prepare($sPeticionExiste);
            $recordset->execute(array($idAgente, date('Y-m-d H:i:s', $this->_iTimestampInicioProceso)));
            $tupla = $recordset->fetch();
            $recordset->closeCursor();
            
            // Se indica éxito de inmediato si ya hay una sesión
            $idAudit = NULL;
            if ($tupla) {
                $idAudit = $tupla['id'];
                $this->_log->output('WARN: '.__METHOD__.": id_agente={$idAgente} ".
                    'inició sesión en '.date('Y-m-d H:i:s', $iTimestampLogin).
                    " pero hay sesión abierta ID={$idAudit}, se reusa.");
            } else {
                // Ingreso de sesión del agente
                $sTimeStamp = date('Y-m-d H:i:s', $iTimestampLogin);
                $sth = $this->_db->prepare('INSERT INTO audit (id_agent, datetime_init) VALUES (?, ?)');
                $sth->execute(array($idAgente, $sTimeStamp));
                $idAudit = $this->_db->lastInsertId();
            }
    
            return $idAudit;
        } catch (PDOException $e) {
            $this->_stdManejoExcepcionDB($e, 'no se puede registrar inicio de sesión de agente');
        	return NULL;
        }
    }

    /**
     * Método para marcar en las tablas de auditoría que el agente ha terminado
     * su sesión principal y está haciendo logout.
     *
     * @param   int     $idAuditSesion  ID de sesión devuelto por marcarInicioSesionAgente()
     * @param   int     $idAuditBreak   ID del break devuelto por marcarInicioBreakAgente().
     *                                  Si es NULL, se ignora (no estaba en break).
     *
     * @return  bool    VERDADERO en éxito, FALSE en error.
     */
    private function _marcarFinalSesionAgente($sAgente, $iTimestampLogout, $idAuditSesion, $idAuditBreak)
    {
        // Quitar posibles pausas sobre el agente
        $this->_ami->QueuePause(NULL, $sAgente, 'false');
        
        if (!is_null($idAuditBreak))
            $this->marcarFinalBreakAgente($idAuditBreak, $iTimestampLogout);
        $sTimeStamp = date('Y-m-d H:i:s', $iTimestampLogout);
        if (!is_null($this->_db)) try {
            $sth = $this->_db->prepare(
                'UPDATE audit SET datetime_end = ?, duration = TIMEDIFF(?, datetime_init) WHERE id = ?');
            $sth->execute(array($sTimeStamp, $sTimeStamp, $idAuditSesion));
            return TRUE;
        } catch (PDOException $e) {
            $this->_stdManejoExcepcionDB($e, 'no se puede registrar final de sesión de agente');
        	return FALSE;
        }
    }

    public function getAsteriskVersion() { return $this->_asteriskVersion; }

    public function marcarInicioBreakAgente($idAgente, $idBreak, $iTimestampInicio)
    {
        // Ingreso de sesión del agente
        $sTimeStamp = date('Y-m-d H:i:s', $iTimestampInicio);
        try {
        	$sth = $this->_db->prepare(
                'INSERT INTO audit (id_agent, id_break, datetime_init) VALUES (?, ?, ?)');
            $sth->execute(array($idAgente, $idBreak, $sTimeStamp));
            return $this->_db->lastInsertId();
        } catch (PDOException $e) {
            $this->_stdManejoExcepcionDB($e, 'no se puede registrar inicio de sesión de agente');
        	return NULL;
        }
    }
    

    /**
     * Método para marcar en las tablas de auditoría que el agente ha terminado
     * su hold o break.
     *
     * @param   int     $idAuditBreak   ID del break devuelto por marcarInicioBreakAgente()
     *
     * @return  bool    VERDADERO en éxito, FALSE en error.
     */
    public function marcarFinalBreakAgente($idAuditBreak, $iTimestampLogout)
    {
        $sTimeStamp = date('Y-m-d H:i:s', $iTimestampLogout);
        try {
        	$sth = $this->_db->prepare(
                'UPDATE audit SET datetime_end = ?, duration = TIMEDIFF(?, datetime_init) WHERE id = ?');
            $sth->execute(array($sTimeStamp, $sTimeStamp, $idAuditBreak));
            return TRUE;
        } catch (PDOException $e) {
        	$this->_stdManejoExcepcionDB($e, 'no se puede registrar final de break de agente');
            return FALSE;
        }
    }

    /**
     * Procedimiento que consulta toda la información de la base de datos sobre
     * una llamada de campaña. Se usa para el evento agentlinked, así como para 
     * el requerimiento getcallinfo.
     * 
     * @param   string  $sTipoLlamada   Uno de 'incoming', 'outgoing'
     * @param   integer $idCampania     ID de la campaña, puede ser NULL para incoming
     * @param   integer $idLlamada      ID de la llamada dentro de la campaña
     *    
     */
    function leerInfoLlamada($sTipoLlamada, $idCampania, $idLlamada)
    {
        switch ($sTipoLlamada) {
        case 'incoming':
            return $this->_leerInfoLlamadaIncoming($idCampania, $idLlamada);
        case 'outgoing':
            return $this->_leerInfoLlamadaOutgoing($idCampania, $idLlamada);
        default:
            return NULL;
        }
    }

    // Leer la información de una llamada saliente. La información incluye lo
    // almacenado en la tabla calls, más los atributos asociados a la llamada
    // en la tabla call_attribute, y los datos ya recogidos en las tablas 
    // form_data_recolected y form_field
    private function _leerInfoLlamadaOutgoing($idCampania, $idLlamada)
    {
        // Leer información de la llamada principal
        $sPeticionSQL = <<<INFO_LLAMADA
SELECT 'outgoing' AS calltype, calls.id AS call_id, id_campaign AS campaign_id, phone, status, uniqueid, 
    duration, datetime_originate, fecha_llamada AS datetime_originateresponse, 
    datetime_entry_queue AS datetime_join, start_time AS datetime_linkstart, 
    end_time AS datetime_linkend, retries, failure_cause, failure_cause_txt,
    agent.number AS agent_number 
FROM (calls) 
LEFT JOIN agent ON agent.id = calls.id_agent 
WHERE id_campaign = ? AND calls.id = ?
INFO_LLAMADA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania, $idLlamada));
        $tuplaLlamada = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
        if (!$tuplaLlamada) {
            // No se encuentra la llamada indicada
            return array();
        }
        if (!is_null($tuplaLlamada['agent_number']))
            $tuplaLlamada['agent_number'] = 'Agent/'.$tuplaLlamada['agent_number'];

        // Leer información de los atributos de la llamada
        $sPeticionSQL = <<<INFO_ATRIBUTOS
SELECT columna AS `label`, value, column_number AS `order`
FROM call_attribute
WHERE id_call = ?
ORDER BY column_number
INFO_ATRIBUTOS;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idLlamada));
        $tuplaLlamada['call_attributes'] = $recordset->fetchAll(PDO::FETCH_ASSOC);

        // Leer información de los datos recogidos vía formularios
        $sPeticionSQL = <<<INFO_FORMULARIOS
SELECT form_field.id_form, form_field.id, form_field.etiqueta AS label, 
    form_data_recolected.value
FROM form_data_recolected, form_field
WHERE form_data_recolected.id_calls = ?
    AND form_data_recolected.id_form_field = form_field.id
ORDER BY form_field.id_form, form_field.orden
INFO_FORMULARIOS;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idLlamada));
        $datosFormularios = $recordset->fetchAll(PDO::FETCH_ASSOC);

        $tuplaLlamada['call_survey'] = array();
        foreach ($datosFormularios as $tuplaFormulario) {
            $tuplaLlamada['call_survey'][$tuplaFormulario['id_form']][] = array(
                'id'    => $tuplaFormulario['id'],
                'label' => $tuplaFormulario['label'],
                'value' => $tuplaFormulario['value'],
            );
        }

        return $tuplaLlamada;
    }

    // Leer la información de la llamada entrante. En esta implementación, a
    // diferencia de las llamadas salientes, las llamadas entrantes tienen un
    // solo formulario, y su conjunto de atributos es fijo.
    private function _leerInfoLlamadaIncoming($idCampania, $idLlamada)
    {
        // Leer información de la llamada principal
        $sPeticionSQL = <<<INFO_LLAMADA
SELECT 'incoming' AS calltype, call_entry.id AS call_id, id_campaign AS campaign_id,
    callerid AS phone, status, uniqueid, duration, datetime_entry_queue AS datetime_join, 
    datetime_init AS datetime_linkstart, datetime_end AS datetime_linkend, 
    trunk, queue, id_contact, agent.number AS agent_number
FROM (call_entry, queue_call_entry)
LEFT JOIN agent ON agent.id = call_entry.id_agent
WHERE call_entry.id = ? AND call_entry.id_queue_call_entry = queue_call_entry.id
INFO_LLAMADA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idLlamada));
        $tuplaLlamada = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
        if (!$tuplaLlamada) {
            // No se encuentra la llamada indicada
            return array();
        }
        if (!is_null($tuplaLlamada['agent_number']))
            $tuplaLlamada['agent_number'] = 'Agent/'.$tuplaLlamada['agent_number'];

        // Leer información de los atributos de la llamada
        // TODO: expandir cuando se tenga tabla de atributos arbitrarios
        $idContact = $tuplaLlamada['id_contact'];
        unset($tuplaLlamada['id_contact']);
        $tuplaLlamada['call_attributes'] = array();
        if (!is_null($idContact)) {
            $sPeticionSQL = <<<INFO_ATRIBUTOS
SELECT name AS first_name, apellido AS last_name, telefono AS phone, cedula_ruc, origen AS contact_source
FROM contact WHERE id = ?
INFO_ATRIBUTOS;
            $recordset = $this->_db->prepare($sPeticionSQL);
            $recordset->execute(array($idContact));
            $atributosLlamada = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
            $tuplaLlamada['call_attributes'] = array(
                array(
                    'label' =>  'first_name',
                    'value' =>  $atributosLlamada['first_name'],
                    'order' =>  1,
                ),
                array(
                    'label' =>  'last_name',
                    'value' =>  $atributosLlamada['last_name'],
                    'order' =>  2,
                ),
                array(
                    'label' =>  'phone',
                    'value' =>  $atributosLlamada['phone'],
                    'order' =>  3,
                ),
                array(
                    'label' =>  'cedula_ruc',
                    'value' =>  $atributosLlamada['cedula_ruc'],
                    'order' =>  4,
                ),
                array(
                    'label' =>  'contact_source',
                    'value' =>  $atributosLlamada['contact_source'],
                    'order' =>  5,
                ),
            );
        }

        // Leer información de todos los contactos que coincidan en callerid
        $tuplaLlamada['matching_contacts'] = array();
        $sPeticionSQL = <<<INFO_ATRIBUTOS
SELECT id, name AS first_name, apellido AS last_name, telefono AS phone, cedula_ruc
FROM contact WHERE telefono = ?
INFO_ATRIBUTOS;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($tuplaLlamada['phone']));
        foreach ($recordset as $tuplaContacto) {
            $tuplaLlamada['matching_contacts'][$tuplaContacto['id']] = array(
                array(
                    'label' =>  'first_name',
                    'value' =>  $tuplaContacto['first_name'],
                    'order' =>  1,
                ),
                array(
                    'label' =>  'last_name',
                    'value' =>  $tuplaContacto['last_name'],
                    'order' =>  2,
                ),
                array(
                    'label' =>  'phone',
                    'value' =>  $tuplaContacto['phone'],
                    'order' =>  3,
                ),
                array(
                    'label' =>  'cedula_ruc',
                    'value' =>  $tuplaContacto['cedula_ruc'],
                    'order' =>  4,
                ),
            );
        }

        // Leer información de los datos recogidos vía formularios
        $idCampaniaTupla = $tuplaLlamada['campaign_id'];
        $sPeticionSQL = <<<INFO_FORMULARIOS
SELECT form_field.id_form, form_field.id, form_field.etiqueta AS label, 
    form_data_recolected_entry.value
FROM form_data_recolected_entry, form_field
WHERE form_data_recolected_entry.id_call_entry = ?
    AND form_data_recolected_entry.id_form_field = form_field.id
ORDER BY form_field.id_form, form_field.orden
INFO_FORMULARIOS;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idLlamada));
        $datosFormularios = $recordset->fetchAll(PDO::FETCH_ASSOC);

        $tuplaLlamada['call_survey'] = array();
        foreach ($datosFormularios as $tuplaFormulario) {
            $tuplaLlamada['call_survey'][$tuplaFormulario['id_form']][] = array(
                'id'    => $tuplaFormulario['id'],
                'label' => $tuplaFormulario['label'],
                'value' => $tuplaFormulario['value'],
            );
        }

        return $tuplaLlamada;
    }

    /**************************************************************************/
    
    public function msg_AgentLogin($sFuente, $sDestino, $sNombreMensaje, 
        $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }

    	list($sAgente, $iTimestampLogin, $id_agent) = $datos;
        if (is_null($id_agent)) {
        	// Ha fallado un intento de login
            $this->_multiplex->notificarEvento_AgentLogin($sAgente, NULL, FALSE);
        } else {
            // Si el agente está en pausa, se la quita ahora
            $this->_ami->QueuePause(NULL, $sAgente, 'false');
        
        	$id_sesion = $this->_marcarInicioSesionAgente($id_agent, $iTimestampLogin);
            if (!is_null($id_sesion)) {
                $this->_tuberia->msg_AMIEventProcess_idNuevaSesionAgente($sAgente, $id_sesion);

                // Notificar a todas las conexiones abiertas
                $this->_multiplex->notificarEvento_AgentLogin($sAgente, TRUE);
            }
        }
    }

    public function msg_AgentLogoff($sFuente, $sDestino, $sNombreMensaje, 
        $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }

        list($sAgente, $iTimestampLogout, $id_agent, $id_sesion, $id_break, $id_hold) = $datos;
        
        // Escribir la información de auditoría en la base de datos
        if (!is_null($id_hold)) {
            // TODO: ¿Qué ocurre con la posible llamada parqueada?
            $this->marcarFinalBreakAgente($id_hold, $iTimestampLogout);
            $this->lanzarEventoPauseEnd($sAgente, $id_hold, 'hold');
        }
        $this->_marcarFinalSesionAgente($sAgente, $iTimestampLogout, $id_sesion, $id_break);
        if (!is_null($id_break))
            $this->lanzarEventoPauseEnd($sAgente, $id_break, 'break');

        // Notificar a todas las conexiones abiertas
        $this->_multiplex->notificarEvento_AgentLogoff($sAgente);
    }

    public function lanzarEventoPauseEnd($sAgente, $id_audit_break, $pause_class)
    {
        try {
            // Obtener inicio, fin y duración de break para lanzar evento
            $recordset = $this->_db->prepare(
                'SELECT break.id AS break_id, break.name AS break_name, '.
                    'audit.datetime_init AS datetime_breakstart, audit.datetime_end AS datetime_breakend, '.
                    'TIME_TO_SEC(audit.duration) AS duration_sec '.
                'FROM audit, break '.
                'WHERE audit.id = ? AND audit.id_break = break.id');
            $recordset->execute(array($id_audit_break));
            $tuplaBreak = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            $paramsEvento = array(
                'pause_class'   =>  $pause_class,
                'pause_start'   =>  $tuplaBreak['datetime_breakstart'],
                'pause_end'     =>  $tuplaBreak['datetime_breakend'],
                'pause_duration'=>  $tuplaBreak['duration_sec'],
            );
            if ($pause_class != 'hold') {
            	$paramsEvento['pause_type'] = $tuplaBreak['break_id'];
                $paramsEvento['pause_name'] = $tuplaBreak['break_name'];
            }
            $this->_multiplex->notificarEvento_PauseEnd($sAgente, $paramsEvento);
        } catch (PDOException $e) {
            $this->_stdManejoExcepcionDB($e, 'no se puede leer final de break de agente');
        }
    }

    public function msg_AgentLinked($sFuente, $sDestino, $sNombreMensaje, 
        $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }

        list($sTipoLlamada, $idCampania, $idLlamada, $sChannel, $sRemChannel, $sFechaLink, $id_agent) = $datos;
        try {
        	$infoLlamada = $this->leerInfoLlamada($sTipoLlamada, $idCampania, $idLlamada);
            /* Ya que la escritura a la base de datos es asíncrona, puede 
             * ocurrir que se lea la llamada en el estado OnQueue y sin fecha
             * de linkstart. */
            if ($infoLlamada['calltype'] == 'incoming')
                $infoLlamada['status'] = 'activa';
            if ($infoLlamada['calltype'] == 'outgoing')
                $infoLlamada['status'] = 'Success';
            $infoLlamada['datetime_linkstart'] = $sFechaLink;
            $this->_multiplex->notificarEvento_AgentLinked($sChannel, $sRemChannel, $infoLlamada);

            // Notificar el progreso de la llamada
            $paramProgreso = array(
                'datetime_entry'    =>  $sFechaLink,
                'new_status'        =>  'Success',
                'id_agent'          =>  $id_agent,
            );
            if ($sTipoLlamada == 'outgoing') {
                $paramProgreso['id_call_outgoing'] = $idLlamada;
                $paramProgreso['id_campaign_outgoing'] = $idCampania;
            } else {
                $paramProgreso['id_call_incoming'] = $idLlamada;
                if (!is_null($idCampania)) $paramProgreso['id_campaign_incoming'] = $idCampania;
            }
            $this->notificarProgresoLlamada($paramProgreso);
        } catch (PDOException $e) {
        	$this->_stdManejoExcepcionDB($e, 'no se puede leer información de llamada para AgentLinked');
        }   	
    }

    public function msg_AgentUnlinked($sFuente, $sDestino, $sNombreMensaje, 
        $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }

        list($sAgente, $sTipoLlamada, $idCampaign, $idLlamada, $sPhone) = $datos;
        $this->_multiplex->notificarEvento_AgentUnlinked($sAgente, array(
            'calltype'      =>  $sTipoLlamada,
            'campaign_id'   =>  $idCampaign,
            'call_id'       =>  $idLlamada,
            'phone'         =>  $sPhone,
        ));
    }

    public function msg_marcarFinalHold($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }

        call_user_func_array(array($this, 'marcarFinalHold'), $datos);
    }
    
    public function marcarFinalHold($iTimestampFinalPausa, $sAgente, $infoLlamada, $infoSeguimiento)
    {
        // Quitar la pausa del agente si es necesario
        if ($infoSeguimiento['num_pausas'] == 1) {
            // La única pausa que quedaba era la del hold
            $r = $this->_ami->QueuePause(NULL, $sAgente, 'false');
            if ($r['Response'] != 'Success') {
                $this->_log->output('ERR: '.__METHOD__.' (internal) no se puede sacar al agente de pausa: '.
                    $sAgente.' - '.$r['Message']);
            }
        }
        
        // Actualizar las tablas de calls y current_calls
        try {
            $this->_db->beginTransaction();
            if ($infoLlamada['calltype'] == 'incoming') {
                $sth = $this->_db->prepare(
                    'UPDATE current_call_entry SET hold = ? WHERE id = ?');
                $sth->execute(array('N', $infoLlamada['currentcallid']));
                $sth = $this->_db->prepare('UPDATE call_entry set status = ? WHERE id = ?');
                $sth->execute(array('activa', $infoLlamada['callid']));
            } else {
                $sth = $this->_db->prepare(
                    'UPDATE current_calls SET hold = ? WHERE id = ?');
                $sth->execute(array('N', $infoLlamada['currentcallid']));
                $sth = $this->_db->prepare('UPDATE calls set status = ? WHERE id = ?');
                $sth->execute(array('Success', $infoLlamada['callid']));
            }
            $this->_db->commit();
        } catch (PDOException $e) {
            $this->_db->rollBack();
            $this->_stdManejoExcepcionDB($e, 'no se puede actualizar información de llamada para final de HOLD');
        }
        
        // Auditoría del fin del hold
        $this->marcarFinalBreakAgente($infoSeguimiento['id_audit_hold'], $iTimestampFinalPausa);
        $this->lanzarEventoPauseEnd($sAgente, $infoSeguimiento['id_audit_hold'], 'hold');
    }

    public function msg_finalizando($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_log->output('INFO: recibido mensaje de finalización, se desconectan conexiones...');
        $this->_finalizandoPrograma = TRUE;
        $this->_multiplex->finalizarConexionesECCP();
        $this->_tuberia->msg_HubProcess_finalizacionTerminada();
    }
    
    public function msg_notificarProgresoLlamada($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }

        call_user_func_array(array($this, 'notificarProgresoLlamada'), $datos);
    }
    
    public function notificarProgresoLlamada($prop)
    {
        if (isset($prop['id_call_incoming'])) $sColLlamada = 'id_call_incoming';
        elseif (isset($prop['id_call_outgoing'])) $sColLlamada = 'id_call_outgoing';
        else {
        	$this->_log->output('WARN: '.__METHOD__.' - no hay asociación con llamada, se ignora.');
            return;
        }
        try {
            /* Se leen las propiedades del último log de la llamada, o NULL si no 
             * hay cambio de estado previo. */
            $recordset = $this->_db->prepare(
                "SELECT retry, uniqueid, trunk, id_agent, duration ".
                "FROM call_progress_log WHERE $sColLlamada = ? ".
                "ORDER BY datetime_entry DESC, id DESC LIMIT 0,1");
            $recordset->execute(array($prop[$sColLlamada]));
            $tuplaAnterior = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            if (!is_array($tuplaAnterior) || count($tuplaAnterior) <= 0) {
            	$tuplaAnterior = array(
                    'retry'             =>  0,
                    'uniqueid'          =>  NULL,
                    'trunk'             =>  NULL,
                    'id_agent'          =>  NULL,
                    'duration'          =>  NULL,
                );
            }

            // Si el número de reintento es distinto, se anulan datos anteriores
            if (isset($prop['retry']) && $tuplaAnterior['retry'] != $prop['retry']) {
                $tuplaAnterior['uniqueid'] = NULL;
                $tuplaAnterior['trunk'] = NULL;
                $tuplaAnterior['id_agent'] = NULL;
                $tuplaAnterior['duration'] = NULL;
            }
            $tuplaAnterior = array_merge($tuplaAnterior, $prop);
            
            // Escribir los valores nuevos en un nuevo registro
            $columnas = array_keys($tuplaAnterior);
            $paramSQL = array();
            foreach ($columnas as $k) $paramSQL[] = $tuplaAnterior[$k];
            $sPeticionSQL = 'INSERT INTO call_progress_log ('.
                implode(', ', $columnas).') VALUES ('.
                implode(', ', array_fill(0, count($columnas), '?')).')';
            $sth = $this->_db->prepare($sPeticionSQL);
            $sth->execute($paramSQL);

            /* Emitir el evento a las conexiones ECCP. Para mantener la 
             * consistencia con el resto del API, se quitan los valores de 
             * id_call_* y id_campaign_*, y se sintetiza tipo_llamada. */
            if (!in_array($tuplaAnterior['new_status'], array('Success', 'Hangup'))) {
                // Todavía no se soporta emitir agente conectado para OnHold/OffHold
                unset($tuplaAnterior['id_agent']);
                
                if (isset($tuplaAnterior['id_call_outgoing'])) {
                    $tuplaAnterior['campaign_type'] = 'outgoing';
                    $tuplaAnterior['campaign_id'] = $tuplaAnterior['id_campaign_outgoing'];
                    $tuplaAnterior['call_id'] = $tuplaAnterior['id_call_outgoing'];
                    unset($tuplaAnterior['id_call_outgoing']);
                    unset($tuplaAnterior['id_campaign_outgoing']);
                } elseif (isset($tuplaAnterior['id_call_incoming'])) {
                    $tuplaAnterior['campaign_type'] = 'incoming';
                    if (isset($tuplaAnterior['id_campaign_incoming']))
                        $tuplaAnterior['campaign_id'] = $tuplaAnterior['id_campaign_incoming'];
                    $tuplaAnterior['call_id'] = $tuplaAnterior['id_call_incoming'];
                    unset($tuplaAnterior['id_call_incoming']);
                    unset($tuplaAnterior['id_campaign_incoming']);
                }
                
                // Agregar el teléfono callerid o marcado
                $recordset = $this->_db->prepare(
                    ($tuplaAnterior['campaign_type'] == 'outgoing') 
                        ?   'SELECT calls.phone, campaign.queue FROM calls, campaign '.
                            'WHERE calls.id_campaign = campaign.id AND calls.id = ?'
                        :   'SELECT call_entry.callerid AS phone, queue_call_entry.queue '.
                            'FROM call_entry, queue_call_entry '.
                            'WHERE call_entry.id_queue_call_entry = queue_call_entry.id AND call_entry.id = ?');
                $recordset->execute(array($tuplaAnterior['call_id']));
                $tuplaNumero = $recordset->fetch(PDO::FETCH_ASSOC);
                $recordset->closeCursor();
                $tuplaAnterior['phone'] = $tuplaNumero['phone'];
                $tuplaAnterior['queue'] = $tuplaNumero['queue'];
                $this->_multiplex->notificarEvento_CallProgress($tuplaAnterior);
            }
        } catch (PDOException $e) {
        	$this->_stdManejoExcepcionDB($e, 'no se puede escribir bitácora de estado de llamada');
        }
    }
}
?>