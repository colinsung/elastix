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

class AMIEventProcess extends TuberiaProcess
{
    private $DEBUG = FALSE; // VERDADERO si se activa la depuración

    private $_log;              // Log abierto por framework de demonio
    private $_config = NULL;    // Configuración informada por CampaignProcess
    private $_ami = NULL;       // Conexión AMI a Asterisk

    private $_listaAgentes;                // Lista de agentes ECCP usados
    private $_campaniasSalientes = array();     // Campañas salientes activas, por ID
    private $_colasEntrantes = array();         // Info de colas entrantes, que puede incluir una campaña entrante
    private $_listaLlamadas;

    // Estimación de la versión de Asterisk que se usa
    private $_asteriskVersion = array(1, 4, 0, 0);
    
    // Fecha y hora de inicio de Asterisk, para detectar reinicios
    private $_asteriskStartTime = NULL;
    private $_bReinicioAsterisk = FALSE;

    private $_finalizandoPrograma = FALSE;
    private $_finalizacionConfirmada = FALSE;
    
    // Contadores para actividades ejecutadas regularmente
    private $_iTimestampVerificacionLlamadasViejas = 0; // Última verificación de llamadas viejas

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
    	$this->_log = $oMainLog;
        $this->_multiplex = new MultiplexServer(NULL, $this->_log);
        $this->_tuberia->registrarMultiplexHijo($this->_multiplex);
        $this->_tuberia->setLog($this->_log);
        $this->_listaLlamadas = new ListaLlamadas($this->_tuberia, $this->_log);
        $this->_listaAgentes = new ListaAgentes();

        // Registro de manejadores de eventos desde CampaignProcess
        foreach (array('nuevaListaAgentes', 'avisoInicioOriginate',
            'idnewcall', 'idcurrentcall', 'canalRemotoAgente', 'actualizarConfig',
            'quitarReservaAgente') as $k)
            $this->_tuberia->registrarManejador('CampaignProcess', $k, array($this, "msg_$k"));
        foreach (array('informarCredencialesAsterisk', 'nuevasCampanias', 
            'leerTiempoContestar', 'nuevasLlamadasMarcar',
            'contarLlamadasEsperandoRespuesta', 'agentesAgendables') as $k)
            $this->_tuberia->registrarManejador('CampaignProcess', $k, array($this, "rpc_$k"));

        // Registro de manejadores de eventos desde ECCPProcess
        foreach (array('idNuevaSesionAgente', 'idNuevoBreakAgente',
            'quitarBreakAgente', 'idNuevoHoldAgente', 'quitarHoldAgente') as $k)
            $this->_tuberia->registrarManejador('ECCPProcess', $k, array($this, "msg_$k"));
        foreach (array('agregarIntentoLoginAgente', 'infoSeguimientoAgente', 
            'reportarInfoLlamadaAtendida', 'reportarInfoLlamadasCampania',
            'cancelarIntentoLoginAgente', 'reportarInfoLlamadasColaEntrante') as $k)
            $this->_tuberia->registrarManejador('ECCPProcess', $k, array($this, "rpc_$k"));

        // Registro de manejadores de eventos desde HubProcess
        $this->_tuberia->registrarManejador('HubProcess', 'finalizando', array($this, "msg_finalizando"));

        return TRUE;
    }
    
    public function procedimientoDemonio()
    {
        // Verificar si la conexión AMI sigue siendo válida
        if (!is_null($this->_config)) {
            if (!is_null($this->_ami) && is_null($this->_ami->sKey)) $this->_ami = NULL;
            if (is_null($this->_ami) && !$this->_finalizandoPrograma) {
            	if (!$this->_iniciarConexionAMI()) {
                    $this->_log->output('ERR: no se puede restaurar conexión a Asterisk, se espera...');
                    if ($this->_multiplex->procesarPaquetes())
                        $this->_multiplex->procesarActividad(0);
                    else $this->_multiplex->procesarActividad(5);
                } else {
                    $this->_log->output('INFO: conexión a Asterisk restaurada, se reinicia operación normal.');
                }
            }
        }

        // Verificar si se ha reiniciado Asterisk en medio de procesamiento
        if (!is_null($this->_ami) && $this->_bReinicioAsterisk) {
        	$this->_bReinicioAsterisk = FALSE;
            
            // Cerrar todas las llamadas
            $listaLlamadas = array();
            foreach ($this->_listaLlamadas as $llamada) {
                $listaLlamadas[] = $llamada;
            }
            foreach ($listaLlamadas as $llamada) {
                $this->_procesarLlamadaColgada($llamada, array(
                    'local_timestamp_received'  =>  microtime(TRUE),
                     'Uniqueid'                 =>  $llamada->Uniqueid,
                     'Channel'                  =>  $llamada->channel,
                     'Cause'                    =>  NULL,
                     'Cause-txt'                =>  NULL,
                ));
            }
            
            // Desconectar a todos los agentes
            foreach ($this->_listaAgentes as $a) {
                if (!is_null($a->id_sesion)) {
                    $this->_tuberia->msg_ECCPProcess_AgentLogoff(
                        $a->channel,
                        microtime(TRUE), 
                        $a->id_agent,
                        $a->id_sesion,
                        $a->id_audit_break,
                        $a->id_audit_hold);
                    $a->terminarLoginAgente();
                }
            }
        }

        // Rutear todos los mensajes pendientes entre tareas
        if ($this->_multiplex->procesarPaquetes())
            $this->_multiplex->procesarActividad(0);
        else $this->_multiplex->procesarActividad(1);
        
        $this->_limpiarLlamadasViejasEspera();
        
    	return TRUE;
    }
    
    public function limpiezaDemonio($signum)
    {
    	
        // Mandar a cerrar todas las conexiones activas
        $this->_multiplex->finalizarServidor();
    }

    /**************************************************************************/

    private function _iniciarConexionAMI()
    {
        if (!is_null($this->_ami)) {
            $this->_log->output('INFO: Desconectando de sesión previa de Asterisk...');
            $this->_ami->disconnect();
            $this->_ami = NULL;            
        }
        $astman = new AMIClientConn($this->_multiplex, $this->_log);
        //$this->_momentoUltimaConnAsterisk = time();

        $this->_log->output('INFO: Iniciando sesión de control de Asterisk...');
        if (!$astman->connect(
                $this->_config['asterisk']['asthost'], 
                $this->_config['asterisk']['astuser'],
                $this->_config['asterisk']['astpass'])) {
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

            /* Ejecutar el comando CoreStatus para obtener la fecha de arranque de 
             * Asterisk. Si se tiene una fecha previa distinta a la obtenida aquí,
             * se concluye que Asterisk ha sido reiniciado */
            $sFechaInicio = '';
            $r = $astman->CoreStatus();
            if (isset($r['Response']) && $r['Response'] == 'Success') {
                $sFechaInicio = $r['CoreStartupDate'].' '.$r['CoreStartupTime'];
                $this->_log->output('INFO: esta instancia de Asterisk arrancó en: '.$sFechaInicio);
            } else {
                $this->_log->output('INFO: esta versión de Asterisk no soporta CoreStatus');
            }
            if (is_null($this->_asteriskStartTime)) {
                $this->_asteriskStartTime = $sFechaInicio;
            } elseif ($this->_asteriskStartTime != $sFechaInicio) {
                $this->_log->output('INFO: esta instancia de Asterisk ha sido reiniciada, se eliminará información obsoleta...');
                $this->_bReinicioAsterisk = TRUE;
            }

            // Instalación de los manejadores de eventos
            foreach (array('Newchannel', 'Dial', 'OriginateResponse', 'Join', 
                'Link', 'Unlink', 'Hangup', 'Agentlogin', 'Agentlogoff',
                'PeerStatus', 'QueueMemberAdded','QueueMemberRemoved') as $k)
                $astman->add_event_handler($k, array($this, "msg_$k"));
            $astman->add_event_handler('Bridge', array($this, "msg_Link")); // Visto en Asterisk 1.6.2.x
            if ($this->DEBUG && $this->_config['dialer']['allevents'])
                $astman->add_event_handler('*', array($this, 'msg_Default'));

            $this->_ami = $astman;
            return TRUE;
        }
    }
    
    private function _infoSeguimientoAgente($sAgente)
    {
    	$a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        return (is_null($a)) ? NULL : $a->resumenSeguimiento();
    }
    
    private function _agregarIntentoLoginAgente($sAgente, $sExtension)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) $a->iniciarLoginAgente($sExtension);
        return !is_null($a);
    }
    
    private function _cancelarIntentoLoginAgente($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) $a->respuestaLoginAgente('Failure', NULL, NULL);
        return !is_null($a);
    }
    
    private function _idNuevaSesionAgente($sAgente, $id_sesion)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            if (!is_null($a->id_sesion) && $a->id_sesion != $id_sesion) {
                $this->_log->output('ERR: '.__METHOD__." - posible carrera, ".
                    "id_sesion ya asignado para $sAgente, se pierde anterior. ".
                    "ID anterior={$a->id_sesion} ID nuevo={$id_sesion}");
            }
            $a->id_sesion = $id_sesion;
        }
    }
    
    private function _idNuevoBreakAgente($sAgente, $idBreak, $idAuditBreak)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            if (!is_null($a->id_break)) {
            	$this->_log->output('ERR: '.__METHOD__." - posible carrera, ".
                    "id_break ya asignado para $sAgente, se pierde anterior.");
            }
            $a->setBreak($idBreak, $idAuditBreak);
        }
    }

    private function _quitarBreakAgente($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            if (!is_null($a->id_break)) { 
                $a->clearBreak();
            }
        }
    }

    private function _idNuevoHoldAgente($sAgente, $idHold, $idAuditHold)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            if (!is_null($a->id_hold)) {
                $this->_log->output('ERR: '.__METHOD__." - posible carrera, ".
                    "id_hold ya asignado para $sAgente, se pierde anterior.");
            }
            $a->setHold($idHold, $idAuditHold);
        }
    }

    private function _quitarHoldAgente($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            if (!is_null($a->id_hold)) { 
                $a->clearHold();
            }
        }
    }
    
    private function _quitarReservaAgente($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            $a->reservado = FALSE;
            if (!is_null($a->llamada_agendada)) {
                $a->llamada_agendada->agente_agendado = NULL;
            	$a->llamada_agendada = NULL;
            }
        }
    }

    private function _reportarInfoLlamadaAtendida($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (is_null($a) || is_null($a->llamada)) return NULL;
        return $a->llamada->resumenLlamada();
    }

    /**
     * Procedimiento que reporta la información sobre todas las llamadas que
     * pertenecen a la campaña indicada por $idCampania. 
     */
    private function _reportarInfoLlamadasCampania($sTipoCampania, $idCampania)
    {
        // Información sobre llamadas que ya están conectadas
        $estadoCola = array();
        $llamadasPendientes = array();
        foreach ($this->_listaLlamadas as $llamada) {
            if (!is_null($llamada->campania) &&
                $llamada->campania->tipo_campania == $sTipoCampania &&
                $llamada->campania->id == $idCampania) {
                $this->_agregarInfoLlamadaCampania($llamada, $estadoCola, $llamadasPendientes);
            }
        }
        ksort($estadoCola);
        return array(
            'queuestatus'   =>  $estadoCola,
            'activecalls'   =>  $llamadasPendientes,
        );        
    }

    /**
     * Procedimiento que reporta la información sobre todas las llamadas que
     * pertenecen a la cola entrante indicada por $sCola y que no pertenecen a
     * una campaña entrante específica.
     */
    private function _reportarInfoLlamadasColaEntrante($sCola)
    {
        // Información sobre llamadas que ya están conectadas
        $estadoCola = array();
        $llamadasPendientes = array();
        if (isset($this->_colasEntrantes[$sCola])) {
            foreach ($this->_listaLlamadas as $llamada) {
                if (is_null($llamada->campania) && 
                    $llamada->id_queue_call_entry == $this->_colasEntrantes[$sCola]['id_queue_call_entry']) {
                    $this->_agregarInfoLlamadaCampania($llamada, $estadoCola, $llamadasPendientes);
                }
            }
        }
        ksort($estadoCola);
        return array(
            'queuestatus'   =>  $estadoCola,
            'activecalls'   =>  $llamadasPendientes,
        );        
    }

    private function _agregarInfoLlamadaCampania($llamada, &$estadoCola, &$llamadasPendientes)
    {
        if (!is_null($llamada->agente)) {
            $a = $llamada->agente;
            $sAgente = $a->channel;
            $estadoCola[$sAgente] = $a->resumenSeguimiento();

            // Agregar información básica sobre la llamada
            $estadoCola[$sAgente]['dialnumber'] = $llamada->phone;
            $estadoCola[$sAgente]['callid'] = $llamada->id_llamada;
            if (!is_null($llamada->timestamp_originatestart))
                $estadoCola[$sAgente]['datetime_dialstart'] = date('Y-m-d H:i:s', $llamada->timestamp_originatestart);
            if (!is_null($llamada->timestamp_originateend))
                $estadoCola[$sAgente]['datetime_dialend'] = date('Y-m-d H:i:s', $llamada->timestamp_originateend);
            $estadoCola[$sAgente]['datetime_enterqueue'] = date('Y-m-d H:i:s', $llamada->timestamp_enterqueue);
            $estadoCola[$sAgente]['datetime_linkstart'] = date('Y-m-d H:i:s', $llamada->timestamp_link);
            if (!is_null($llamada->trunk)) $estadoCola[$sAgente]['trunk'] = $llamada->trunk;
        } elseif (in_array($llamada->status, array('Placing', 'Ringing', 'OnQueue'))) {
            $callStatus = array(
                'dialnumber'    =>  $llamada->phone,
                'callid'        =>  $llamada->id_llamada,
                'callstatus'    =>  $llamada->status,
            );
            if (!is_null($llamada->timestamp_originatestart))
                $callStatus['datetime_dialstart'] = date('Y-m-d H:i:s', $llamada->timestamp_originatestart);
            if (!is_null($llamada->timestamp_originateend))
                $callStatus['datetime_dialend'] = date('Y-m-d H:i:s', $llamada->timestamp_originateend);
            if (!is_null($llamada->timestamp_enterqueue))
                $callStatus['datetime_enterqueue'] = date('Y-m-d H:i:s', $llamada->timestamp_enterqueue);
            if (!is_null($llamada->trunk)) $callStatus['trunk'] = $llamada->trunk;
            
            $llamadasPendientes[] = $callStatus;
        }
    }

    private function _manejarLlamadaEspecialECCP($params)
    {
    	$sKey = $params['ActionID'];
        
        // Se revisa si esta es una de las llamadas para logonear un agente estático
        $listaECCP = explode(':', $sKey);
        if ($listaECCP[0] == 'ECCP' /*&& $listaECCP[2] == posix_getpid()*/) {
            switch ($listaECCP[3]) {
            case 'AgentLogin':
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: AgentLogin({$listaECCP[4]}) detectado");
                }
                $a = $this->_listaAgentes->buscar('agentchannel', $listaECCP[4]);
                if (is_null($a)) {
                    $this->_log->output("ERR: ".__METHOD__.": no se ha ".
                        "cargado información de agente {$listaECCP[4]}");
                    $this->_tuberia->msg_CampaignProcess_requerir_nuevaListaAgentes();
                } else {
                    $a->respuestaLoginAgente(
                        $params['Response'], $params['Uniqueid'], $params['Channel']);
                    if ($params['Response'] == 'Success') {
                        if ($this->DEBUG) {
                            $this->_log->output("DEBUG: AgentLogin({$listaECCP[4]}) ".
                                "llamada contestada, esperando clave de agente...");
                        }
                    } else {
                        if ($this->DEBUG) {
                            $this->_log->output("DEBUG: AgentLogin({$listaECCP[4]}) ".
                                "llamada ha fallado.");
                        }
                        $this->_tuberia->msg_ECCPProcess_AgentLogin(
                            $listaECCP[4],
                            $params['local_timestamp_received'],
                            NULL);                    
                    }
                }
                return TRUE;
            case 'RedirectFromHold':
                /* Por ahora se ignora el OriginateResponse resultante del Originate
                 * para regresar de HOLD */
                return TRUE;
            case 'QueueMemberAdded':
                /* Nada que hacer */
                $this->_log->output("DEBUG: ".__METHOD__.": QueueMemberAdded detectado");  
                return TRUE;
            default:
                $this->_log->output("ERR: ".__METHOD__.": no se ha implementado soporte ECCP para: {$sKey}");
                return TRUE;
            }
        }
        return FALSE;   // Llamada NO es una llamada especial ECCP
    }

    private function _manejarHangupAgentLoginFallido($params)
    {
        $a = $this->_listaAgentes->buscar('uniqueidlogin', $params['Uniqueid']);
        if (is_null($a)) return FALSE;
        $a->respuestaLoginAgente('Failure', NULL, NULL);
        $this->_tuberia->msg_ECCPProcess_AgentLogin(
            $a->channel,
            $params['local_timestamp_received'],
            NULL);
        if ($this->DEBUG) {
            $this->_log->output("DEBUG: AgentLogin({$a->channel}) cuelga antes de ".
                "introducir contraseña");
        }
        return TRUE;
    }

    private function _nuevasCampanias($listaCampaniasAvisar)
    {
        // Nuevas campañas salientes
        foreach ($listaCampaniasAvisar['outgoing'] as $id => $tupla) {
        	if (!isset($this->_campaniasSalientes[$id])) {
                $this->_campaniasSalientes[$id] = new Campania($this->_tuberia, $this->_log);
                $this->_campaniasSalientes[$id]->tiempoContestarOmision(
                    $this->_config['dialer']['tiempo_contestar']);
                if ($this->DEBUG) {
                	$this->_log->output('DEBUG: '.__METHOD__.': nueva campaña saliente: '.print_r($tupla, 1));
                }
            }
            $c = $this->_campaniasSalientes[$id];
            $c->tipo_campania = 'outgoing';
            $c->id = (int)$tupla['id'];
            $c->name = $tupla['name'];
            $c->queue = $tupla['queue'];
            $c->datetime_init = $tupla['datetime_init'];
            $c->datetime_end = $tupla['datetime_end'];
            $c->daytime_init = $tupla['daytime_init'];
            $c->daytime_end = $tupla['daytime_end'];
            $c->trunk = $tupla['trunk'];
            $c->context = $tupla['context'];
            $c->estadisticasIniciales($tupla['num_completadas'], $tupla['promedio'], $tupla['desviacion']);
        }
        
        // Purgar todas las campañas entrantes fuera de horario
        $iTimestamp = time();
        $sFecha = date('Y-m-d', $iTimestamp);
        $sHora = date('H:i:s', $iTimestamp);
        foreach (array_keys($this->_colasEntrantes) as $queue) {
        	if (!is_null($this->_colasEntrantes[$queue]['campania'])) {
                $c = $this->_colasEntrantes[$queue]['campania'];
                if ($c->datetime_end < $sFecha)
                    $this->_colasEntrantes[$queue]['campania'] = NULL;
                elseif ($c->daytime_init <= $c->daytime_end && !($c->daytime_init <= $sHora && $sHora <= $c->daytime_end))
                    $this->_colasEntrantes[$queue]['campania'] = NULL;
                elseif ($c->daytime_init > $c->daytime_end && ($c->daytime_end < $sHora && $sHora < $c->daytime_init))
                    $this->_colasEntrantes[$queue]['campania'] = NULL;
                if ($this->DEBUG && is_null($this->_colasEntrantes[$queue]['campania'])) {
                	$this->_log->output('DEBUG: '.__METHOD__.': campaña entrante '.
                        'quitada por salir de horario: '.sprintf('%d %s', $c->id, $c->name));
                }
            }
        }
        
        // Quitar las colas aisladas que no tengan asociada una campaña entrante
        foreach ($listaCampaniasAvisar['incoming_queue_old'] as $id => $tupla) {
        	if (isset($this->_colasEntrantes[$tupla['queue']])) {
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': cola entrante '.
                        'quitada: '.$tupla['queue']);
                }
                unset($this->_colasEntrantes[$tupla['queue']]);
            }
        }
        
        // Crear nuevos registros para las nuevas colas aisladas
        foreach ($listaCampaniasAvisar['incoming_queue_new'] as $id => $tupla) {
            if (!isset($this->_colasEntrantes[$tupla['queue']]))
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': cola entrante '.
                        'agregada: '.$tupla['queue']);
                }
                $this->_colasEntrantes[$tupla['queue']] = array(
                    'id_queue_call_entry'   =>  $id,
                    'queue'                 =>  $tupla['queue'],
                    'campania'              =>  NULL,
                );
        }
        
        // Crear nuevas campañas que entran en servicio
        foreach ($listaCampaniasAvisar['incoming'] as $id => $tupla) {
            if (!isset($this->_colasEntrantes[$tupla['queue']]))
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': cola entrante '.
                        'agregada: '.$tupla['queue']);
                }
                $this->_colasEntrantes[$tupla['queue']] = array(
                    'id_queue_call_entry'   =>  $tupla['id_queue_call_entry'],
                    'queue'                 =>  $tupla['queue'],
                    'campania'              =>  NULL,
                );
            if (is_null($this->_colasEntrantes[$tupla['queue']]['campania'])) {
            	$c = new Campania($this->_tuberia, $this->_log);
                $c->tipo_campania = 'incoming';
                $c->id = (int)$tupla['id'];
                $c->name = $tupla['name'];
                $c->queue = $tupla['queue'];
                $c->datetime_init = $tupla['datetime_init'];
                $c->datetime_end = $tupla['datetime_end'];
                $c->daytime_init = $tupla['daytime_init'];
                $c->daytime_end = $tupla['daytime_end'];
                $c->id_queue_call_entry = $tupla['id_queue_call_entry'];
                $this->_colasEntrantes[$tupla['queue']]['campania'] = $c;

                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': nueva campaña entrante: '.print_r($tupla, 1));
                }
            }
        }
        
        /*
        if ($this->DEBUG) {
            // Desactivado porque rellena demasiado el log
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($this->_campaniasSalientes, 1));
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($this->_colasEntrantes, 1));
        }
        */
    	return TRUE;
    }

    private function _nuevasLlamadasMarcar($listaLlamadas)
    {
    	$listaKeyRepetidos = array();
        foreach ($listaLlamadas as $k => $tupla) {
    		$llamada = $this->_listaLlamadas->buscar('dialstring', $tupla['dialstring']);
            if (!is_null($llamada)) {
            	// Llamada monitoreada repetida en dialstring
                $listaKeyRepetidos[] = $k;
            } else {
            	// Llamada nueva, se procede normalmente
                if (!isset($this->_campaniasSalientes[$tupla['id_campaign']])) {
                	$this->_log->output('ERR: '.__METHOD__.": no se encuentra ".
                        "campaña {$tupla['id_campaign']} requerida por llamada: ".
                        print_r($tupla, 1));
                } else {
                    $llamada = $this->_listaLlamadas->nuevaLlamada('outgoing');
                    $llamada->id_llamada = $tupla['id'];
                    $llamada->phone = $tupla['phone'];
                    $llamada->actionid = $tupla['actionid'];
                    $llamada->dialstring = $tupla['dialstring'];
                    $llamada->campania = $this->_campaniasSalientes[$tupla['id_campaign']];
                    
                    if (!is_null($tupla['agent'])) {
                    	$a = $this->_listaAgentes->buscar('agentchannel', $tupla['agent']);
                        if (is_null($a)) {
                        	$this->_log->output('ERR: '.__METHOD__.": no se ".
                                "encuentra agente para llamada agendada: {$tupla['agent']}");
                        } elseif (!$a->reservado) {
                            $this->_log->output('ERR: '.__METHOD__.": agente no ".
                                "fue reservado para llamada agendada: {$tupla['agent']}");
                        } else {
                        	$a->llamada_agendada = $llamada;
                            $llamada->agente_agendado = $a;
                        }
                    }
                }
            }
    	}
        return $listaKeyRepetidos;
    }

    private function _avisoInicioOriginate($sActionID, $iTimestampInicioOriginate)
    {
    	$llamada = $this->_listaLlamadas->buscar('actionid', $sActionID);
        if (is_null($llamada)) {
        	$this->_log->output('ERR: '.__METHOD__." no se encuentra llamada con ".
                "actionid=$sActionID para aviso de resultado de Originate");
        } else {
        	// Un timestamp NULL indica fallo de Originate y quitar la llamada
            if (is_null($iTimestampInicioOriginate)) {
            	$this->_listaLlamadas->remover($llamada);
                if (!is_null($llamada->agente_agendado)) {
                	$a = $llamada->agente_agendado;
                    $llamada->agente_agendado = NULL;
                    $a->llamada_agendada = NULL;
                    
                    /* Se debe quitar la reservación únicamente si no hay más
                     * llamadas agendadas para este agente. Si se cumple esto,
                     * CampaignProcess lanzará el evento quitarReservaAgente
                     * luego de quitar la pausa del agente. */
                    $this->_tuberia->msg_CampaignProcess_verificarFinLlamadasAgendables(
                        $a->channel, $llamada->campania->id, $a->resumenSeguimiento());
                }
            } else {
            	$llamada->timestamp_originatestart = $iTimestampInicioOriginate;
                $llamada->status = 'Placing';
            }
        }
    }

    private function _idnewcall($tipo_llamada, $uniqueid, $id_call)
    {
    	if ($tipo_llamada == 'incoming') {
    		$llamada = $this->_listaLlamadas->buscar('uniqueid', $uniqueid);
            if (is_null($llamada)) {
            	$this->_log->output('ERR: '.__METHOD__." no se encuentra llamada con tipo=$tipo_llamada id=$id_call");
            } else {
            	$llamada->id_llamada = $id_call;
            }
    	} else {
    		$this->_log->output('ERR: '.__METHOD__." no se ha implementado llamada con tipo=$tipo_llamada id=$id_call");
    	}
    }

    private function _idcurrentcall($tipo_llamada, $id_call, $id_current_call)
    {
    	$llamada = NULL;
        if ($tipo_llamada == 'outgoing')
            $llamada = $this->_listaLlamadas->buscar('id_llamada_saliente', $id_call);
        elseif ($tipo_llamada == 'incoming')
            $llamada = $this->_listaLlamadas->buscar('id_llamada_entrante', $id_call);
        if (is_null($llamada)) {
        	$this->_log->output('ERR: '.__METHOD__." no se encuentra llamada con tipo=$tipo_llamada id=$id_call");
        } else {
        	$llamada->id_current_call = (int)$id_current_call;
        }
    }

    private function _canalRemotoAgente($sAgentNum, $tipo_llamada, $uniqueid, $sCanalRemoto)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.': CampaignProcess avisa un canal remoto '.$sCanalRemoto);
        }
        $llamada = NULL;
        $llamada = $this->_listaLlamadas->buscar('uniqueid', $uniqueid);
        if (is_null($llamada)) {
            $this->_log->output('ERR: '.__METHOD__.": no se encuentra llamada con tipo=$tipo_llamada uniqueid=$uniqueid");
        } elseif (!is_null($llamada->agente) && $llamada->agente->number == $sAgentNum) {
            if (is_null($llamada->actualchannel)) {
                $llamada->actualchannel = $sCanalRemoto;

            } elseif ($llamada->actualchannel != $sCanalRemoto) {
                $this->_log->output('WARN: '.__METHOD__.
                    ": llamada con tipo=$tipo_llamada id=$id_call canal remoto ".
                    "recogido en Link auxiliar fue {$llamada->actualchannel} pero realmente es {$sCanalRemoto}");
                $llamada->actualchannel = $sCanalRemoto;
            }
            // TODO: actualizar current_calls con canal remoto
        }
    }
    
    private function _agentesAgendables($listaAgendables)
    {
    	$entraronPausa = array();
        $ociosos = array();
        foreach ($this->_listaAgentes as $a) if ($a->estado_consola == 'logged-in') {
    		$sAgente = $a->channel;
            if (in_array($sAgente, $listaAgendables)) {
            	// Agente sí está agendado
                if (!$a->reservado) {
                	$a->reservado = TRUE;
                    if ($a->num_pausas == 1) $entraronPausa[] = $sAgente;
                }
            }
            
            /* Un agente ocioso para agendamiento debe estar reservado, sin 
             * llamada activa, sin llamada agendada, y sin ninguna otra pausa.
             */
            if ($a->reservado && 
                is_null($a->llamada) && 
                is_null($a->llamada_agendada) &&
                $a->num_pausas == 1)
                $ociosos[] = $sAgente;
    	}
        return array(
            'entraron'  =>  $entraronPausa,
            'ociosos'   =>  $ociosos,
        );
    }
    
    private function _actualizarConfig($k, $v)
    {
    	switch ($k) {
    	case 'asterisk_cred':
            $this->_log->output('INFO: actualizando credenciales de Asterisk...');
            $this->_config['asterisk']['asthost'] = $v[0];
            $this->_config['asterisk']['astuser'] = $v[1];
            $this->_config['asterisk']['astpass'] = $v[2];
            $this->_iniciarConexionAMI();
            break;
        /*
        case 'asterisk_duracion_sesion':
            $this->_log->output('INFO: actualizando duración de sesión a '.$v);
            $this->_config['asterisk']['duracion_sesion'] = $v;
            break;
        */
        case 'dialer_llamada_corta':
            $this->_log->output('INFO: actualizando intervalo de llamada corta a '.$v);
            $this->_config['dialer']['llamada_corta'] = $v;
            break;
        case 'dialer_tiempo_contestar': 
            $this->_log->output('INFO: actualizando intervalo inicial de contestar a '.$v);
            $this->_config['dialer']['tiempo_contestar'] = $v;
            foreach ($this->_campaniasSalientes as $c) {
            	$c->tiempoContestarOmision($v);
            }
            break;
        case 'dialer_debug':
            $this->_log->output('INFO: actualizando DEBUG...');
            $this->_config['dialer']['debug'] = $v;
            $this->DEBUG = $this->_config['dialer']['debug'];
            break;
        case 'dialer_allevents':
            $this->_config['dialer']['allevents'] = $v;
            if (!is_null($this->_ami)) {
            	$this->_ami->remove_event_handler('*');
                if ($v) $this->_ami->add_event_handler('*', array($this, 'msg_Default'));
            }
            break;
        default:
            $this->_log->output('WARN: '.__METHOD__.': se ignora clave de config no implementada: '.$k);
            break;
    	}
    }
    
    private function _limpiarLlamadasViejasEspera()
    {
    	$iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampVerificacionLlamadasViejas > 30) {
        	// Remover llamadas viejas luego de 5 * 60 segundos de espera sin respuesta
            $listaLlamadasViejas = array();
            foreach ($this->_listaLlamadas as $llamada) {
        		if (!is_null($llamada->timestamp_originatestart) && 
                    is_null($llamada->timestamp_originateend) && 
                    $iTimestamp - $llamada->timestamp_originatestart > 5 * 60) {
                    $listaLlamadasViejas[] = $llamada;
                }
        	}
            
            foreach ($listaLlamadasViejas as $llamada) {
            	$iEspera = $iTimestamp - $llamada->timestamp_originatestart;
                $this->_log->output('ERR: '.__METHOD__.": llamada {$llamada->actionid} ".
                    "espera respuesta desde hace $iEspera segundos, se elimina.");
                $llamada->llamadaFueOriginada($iTimestamp, NULL, NULL, 'Failure');
            }
            
            $this->_iTimestampVerificacionLlamadasViejas = $iTimestamp;
        }
    }

    private function _verificarFinalizacionLlamadas()
    {
        if (!$this->_finalizacionConfirmada) {
            if (!is_null($this->_ami)) {
                foreach ($this->_listaAgentes as $a) {
                	if ($a->estado_consola != 'logged-out') return;
                }
                if ($this->_listaLlamadas->numLlamadas() > 0) return;
            }
            $this->_tuberia->msg_CampaignProcess_finalsql();
            $this->_tuberia->msg_HubProcess_finalizacionTerminada();
            $this->_finalizacionConfirmada = TRUE;
        }
    }

    /* Deshacerse de todas las llamadas monitoreadas bajo la premisa de que 
     * Asterisk se ha caído anormalmente y ya no está siguiendo llamadas */ 
    private function _abortarTodasLasLlamadas()
    {
    	/* Copiar todas las llamadas a una lista temporal. Esto es necesario 
         * para poder modificar la lista principal. */
        $listaLlamadasRemover = array();
        foreach ($this->_listaLlamadas as $llamada)
            $listaLlamadasRemover[] = $llamada;
        
        $this->_log->output("WARN: abortando todas las llamadas activas...");
        foreach ($listaLlamadasRemover as $llamada) {
        	if (is_null($llamada->status)) {
                // Llamada no ha sido iniciada todavía
        		$this->_listaLlamadas->remover($llamada);
                if (!is_null($llamada->agente_agendado)) {
                    $a = $llamada->agente_agendado;
                    $llamada->agente_agendado = NULL;
                    $a->llamada_agendada = NULL;
                    
                    /* No puedo verificar estado de reserva de agente porque
                     * se requiere de la conexión a Asterisk.*/
                }
        	} else switch ($llamada->status) {
        	case 'Placing':
                $llamada->llamadaFueOriginada(time(), NULL, NULL, 'Failure');
                break;
            case 'Ringing':
            case 'OnQueue':
            case 'Success':
            case 'OnHold':
                $llamada->llamadaFinalizaSeguimiento(time(),
                        $this->_config['dialer']['llamada_corta']);
                break;
            default:
                $this->_log->output("WARN: estado extraño {$llamada->status} al abortar llamada");
                $llamada->llamadaFinalizaSeguimiento(time(),
                        $this->_config['dialer']['llamada_corta']);
                break;
        	}
        }
    }

    /**************************************************************************/

    public function rpc_informarCredencialesAsterisk($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_config = $datos[0];
        $bExito = $this->_iniciarConexionAMI();

        $this->DEBUG = $this->_config['dialer']['debug'];        

        // Informar a la fuente que se ha terminado de procesar
        $this->_tuberia->enviarRespuesta($sFuente, $bExito);
    }

    public function rpc_leerTiempoContestar($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
    	$this->_tuberia->enviarRespuesta($sFuente, 
            isset($this->_campaniasSalientes[$datos[0]]) 
            ? $this->_campaniasSalientes[$datos[0]]->leerTiempoContestar() 
            : NULL);        
    }

    public function rpc_contarLlamadasEsperandoRespuesta($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
    	$queue = $datos[0];
        $iNumEspera = 0;

        foreach ($this->_listaLlamadas as $llamada) {
        	if (!is_null($llamada->campania) && 
                $llamada->campania->queue == $queue && 
                $llamada->esperando_contestar) {
                $iNumEspera++;
                if ($this->DEBUG) {
                	$iEspera = time() - $llamada->timestamp_originatestart;
                    $this->_log->output("DEBUG: ".__METHOD__.": llamada {$llamada->actionid} ".
                        "espera respuesta desde hace $iEspera segundos.");
                }
            }
        }
        if ($this->DEBUG && $iNumEspera > 0) {
        	$this->_log->output("DEBUG: ".__METHOD__.": en campaña en cola $queue todavía ".
                "quedan $iNumEspera llamadas pendientes de OriginateResponse.");
        }
        $this->_tuberia->enviarRespuesta($sFuente, $iNumEspera);
    }

    public function rpc_nuevasCampanias($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
    	if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_nuevasCampanias'), $datos));
    }

    public function rpc_nuevasLlamadasMarcar($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_nuevasLlamadasMarcar'), $datos));
    }

    public function rpc_agregarIntentoLoginAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_agregarIntentoLoginAgente'), $datos));
    }
    
    public function rpc_cancelarIntentoLoginAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_cancelarIntentoLoginAgente'), $datos));
    }
    
    public function rpc_infoSeguimientoAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_infoSeguimientoAgente'), $datos));
    }
    
    public function rpc_reportarInfoLlamadaAtendida($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_reportarInfoLlamadaAtendida'), $datos));
    }
    
    public function rpc_reportarInfoLlamadasCampania($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_reportarInfoLlamadasCampania'), $datos));
    }
    
    public function rpc_agentesAgendables($sFuente, $sDestino, $sNombreMensaje, 
        $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_agentesAgendables'), $datos));
    }

    public function rpc_reportarInfoLlamadasColaEntrante($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_reportarInfoLlamadasColaEntrante'), $datos));
    }
    
    /**************************************************************************/

    public function msg_nuevaListaAgentes($sFuente, $sDestino, $sNombreMensaje, 
        $iTimestamp, $datos)
    {
        foreach ($datos[0] as $tupla) {
            // id type number name estatus
        	$sAgente = $tupla['type'].'/'.$tupla['number'];
            $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
            if (is_null($a)) {
            	// Agente nuevo por registrar
                $a = $this->_listaAgentes->nuevoAgente($tupla['id'], 
                    $tupla['number'], $tupla['name'], ($tupla['estatus'] == 'A'),
                    $tupla['type']);
            } elseif ($a->id_agent != $tupla['id']) {
            	// Agente ha cambiado de ID de base de datos, y está deslogoneado
                if ($a->estado_consola == 'logged-out') {
                    $this->_log->output("INFO: agente deslogoneado $sAgente cambió de ID de base de datos");
                    $a->id_agent = $tupla['id'];
                    $a->number = $tupla['number'];
                    $a->name = $tupla['name'];
                    $a->estatus = ($tupla['estatus'] == 'A');
                } else {
                	$this->_log->output("INFO: agente $sAgente cambió de ID de base de datos pero está ".
                        $a->estado_consola);
                }
            }
        }
    }
    
    public function msg_avisoInicioOriginate($sFuente, $sDestino, $sNombreMensaje, 
        $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_avisoInicioOriginate'), $datos);
    }
    
    public function msg_idNuevaSesionAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_idNuevaSesionAgente'), $datos);
    }
    
    public function msg_idNuevoBreakAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_idNuevoBreakAgente'), $datos);
    }

    public function msg_quitarBreakAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_quitarBreakAgente'), $datos);
    }

    public function msg_idNuevoHoldAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_idNuevoHoldAgente'), $datos);
    }

    public function msg_quitarHoldAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_quitarHoldAgente'), $datos);
    }
    
    public function msg_quitarReservaAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_quitarReservaAgente'), $datos);
    }

    public function msg_idnewcall($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_idnewcall'), $datos);
    }
    
    public function msg_idcurrentcall($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_idcurrentcall'), $datos);
    }
    
    public function msg_canalRemotoAgente($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_canalRemotoAgente'), $datos);
    }
    
    public function msg_actualizarConfig($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_actualizarConfig'), $datos);
    }

    public function msg_finalizando($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_log->output('INFO: recibido mensaje de finalización, se desloguean agentes...');
        $this->_finalizandoPrograma = TRUE;
        foreach ($this->_listaAgentes as $a) {
        	if ($a->estado_consola != 'logged-out') {
                if (!is_null($this->_ami)) $this->_ami->Agentlogoff($a->number);
            }
        }
        $this->_log->output('INFO: esperando a que finalicen todas las llamadas monitoreadas...');
        $this->_verificarFinalizacionLlamadas();
    }

    /**************************************************************************/
    
    public function msg_Default($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
    }
    
    public function msg_Newchannel($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
        $regs = NULL;
        if (isset($params['Channel']) && 
            preg_match('#^(Local/.+@from-internal)-[\dabcdef]+(,|;)(1|2)$#', $params['Channel'], $regs)) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": se ha creado pata {$regs[3]} de llamada {$regs[1]}");         
            }
            $llamada = $this->_listaLlamadas->buscar('dialstring', $regs[1]);
            if (!is_null($llamada)) {
                if ($regs[3] == '1') {
                    // Pata 1, se requiere para los eventos Link/Join
                    $llamada->uniqueid = $params['Uniqueid'];
                    if ($this->DEBUG) {
                        $this->_log->output("DEBUG: ".__METHOD__.": Llamada localizada, Uniqueid={$params['Uniqueid']}");
                    }
                } elseif ($regs[3] == '2') {
                    /* Pata 2, se requiere para recuperar razón de llamada 
                     * fallida, en caso de que se desconozca vía pata 1. Además
                     * permite reconocer canal físico real al recibir Link sobre
                     * pata auxiliar. */
                    $llamada->AuxChannels[$params['Uniqueid']] = array(); 
                    $llamada->registerAuxChannels();
                    if ($this->DEBUG) {
                        $this->_log->output("DEBUG: ".__METHOD__.": Llamada localizada canal auxiliar Uniqueid={$params['Uniqueid']}");
                    }
                }
            }
        }
        
        return FALSE;
    }
    
    public function msg_Dial($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }

        /*
        Asterisk 1.4.x
        2010-05-20 16:01:38 : (DialerProcess) DEBUG: dial:
        params => Array
        (
            [Event] => Dial
            [Privilege] => call,all
            [Source] => Local/96350440@from-internal-a2b9,2
            [Destination] => SIP/telmex-0000004c
            [CallerID] => <unknown>
            [CallerIDName] => <unknown>
            [SrcUniqueID] => 1274385698.159
            [DestUniqueID] => 1274385698.160
        )
        
        Asterisk 1.6.2.x
        2010-10-08 18:49:18 : (DialerProcess) DEBUG: dial:
        params => Array
        (
            [Event] => Dial
            [Privilege] => call,all
            [SubEvent] => Begin
            [Channel] => Local/1065@from-internal-fd98;2
            [Destination] => SIP/1065-00000003
            [CallerIDNum] => <unknown>
            [CallerIDName] => <unknown>
            [UniqueID] => 1286581757.4
            [DestUniqueID] => 1286581758.5
            [Dialstring] => 1065
        )
        */

        $srcUniqueId = $destUniqueID = NULL;
        if (isset($params['SrcUniqueID']))
            $srcUniqueId = $params['SrcUniqueID'];
        elseif (isset($params['UniqueID']))
            $srcUniqueId = $params['UniqueID'];
        if (isset($params['DestUniqueID']))
            $destUniqueID = $params['DestUniqueID'];

        if (!is_null($srcUniqueId) && !is_null($destUniqueID)) {
            /* Si el SrcUniqueID es alguno de los Uniqueid monitoreados, se añade el
             * DestUniqueID correspondiente. Potencialmente esto permite también 
             * trazar la troncal por la cual salió la llamada. 
             */
            $llamada = $this->_listaLlamadas->buscar('uniqueid', $srcUniqueId);
            if (is_null($llamada))
                $llamada = $this->_listaLlamadas->buscar('auxchannel', $srcUniqueId);
            if (!is_null($llamada)) {
            	$llamada->AuxChannels[$destUniqueID]['Dial'] = $params;
                $llamada->registerAuxChannels();
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": encontrado canal auxiliar para llamada: {$llamada->actionid}");         
                }

                if (strpos($params['Destination'], 'Local/') !== 0) {
                    if (is_null($llamada->actualchannel)) {
                        $llamada->actualchannel = $params['Destination'];
                        if ($this->DEBUG) {
                            $this->_log->output('DEBUG: '.__METHOD__.
                                ': capturado canal remoto real: '.$params['Destination']);
                        }
                        
                        // Notificar el progreso de la llamada
                        $paramProgreso = array(
                            'datetime_entry'=>  date('Y-m-d H:i:s', $params['local_timestamp_received']),
                            'new_status'    =>  'Dialing',
                            'trunk'         =>  $llamada->trunk,                            
                        );
                        if ($llamada->tipo_llamada == 'outgoing') {
                            $paramProgreso['id_call_outgoing'] = $llamada->id_llamada;
                            $paramProgreso['id_campaign_outgoing'] = $llamada->campania->id;
                        } else {
                            $paramProgreso['id_call_incoming'] = $llamada->id_llamada;
                            if (!is_null($llamada->campania)) $paramProgreso['id_campaign_incoming'] = $llamada->campania->id;
                        }
                        $this->_tuberia->msg_ECCPProcess_notificarProgresoLlamada($paramProgreso);
                    } elseif ($llamada->actualchannel != $params['Destination']) {
                        $regs = NULL;
                        if (preg_match('|^(\w+/\d+)(\-\w+)?$|', $params['Destination'], $regs)) {
                        	$sCanalPosibleAgente = $regs[1];
                            $a = $this->_listaAgentes->buscar('agentchannel', $sCanalPosibleAgente);
                            if (!is_null($a) && $a->estado_consola == 'logged-in') {
                            	if ($this->DEBUG) {
                            		$this->_log->output('DEBUG: '.__METHOD__.': canal remoto es agente, se ignora.');
                            	}
                            } else {
                                $sCanalPosibleAgente = NULL;
                            }
                        }
                        if (is_null($sCanalPosibleAgente)) {
                            $this->_log->output('WARN: '.__METHOD__.': canal remoto en '.
                                'conflicto, anterior '.$llamada->actualchannel.' nuevo '.
                                $params['Destination']);
                        }
                    }
                }
            }
        }
        
        return FALSE;
    }
    
    public function msg_OriginateResponse($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
        
        // Todas las llamadas del dialer contienen un ActionID
        if (!isset($params['ActionID'])) return FALSE;
        
        // Verificar si esta es una llamada especial de ECCP
        if ($this->_manejarLlamadaEspecialECCP($params)) return FALSE;
        
        $llamada = $this->_listaLlamadas->buscar('actionid', $params['ActionID']);
        if (is_null($llamada)) return FALSE;

        $llamada->llamadaFueOriginada($params['local_timestamp_received'], 
            $params['Uniqueid'], $params['Channel'], $params['Response']);

        // Si la fuente de la llamada está en blanco, se asigna al número marcado
        $r = $this->_ami->GetVar($params['Channel'], 'CALLERID(num)');
        if ($r['Response'] != 'Success') {
        	$this->_log->output('ERR: '.__METHOD__.
                ': fallo en obtener CALLERID(num) para canal '.$params['Channel'].
                ': '.print_r($r, 1));
        } else {
            if ($r['Value'] == '(null)') $r['Value'] = '';
            if (empty($r['Value'])) {
                $r = $this->_ami->SetVar($params['Channel'], 'CALLERID(num)', $llamada->phone);
                if ($r['Response'] != 'Success') {
                    $this->_log->output('ERR: '.__METHOD__.
                        ': fallo en asignar CALLERID(num) para canal '.$params['Channel'].
                        ': '.print_r($r, 1));
                }
            }
        }
        return FALSE;
    }
    
    // Nueva función
    public function msg_QueueMemberAdded($sEvent, $params, $sServer, $iPort)
    {  
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }      

       $sAgente = $params['Location']; 
       
       /* tomado de msg_agentLogin */
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);

        /* if (is_null($a) || $a->estado_consola == 'logged-out') { // Línea original */ 
       if (is_null($a)) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": AgentLogin($sAgente) no iniciado por programa, no se hace nada.");
                $this->_log->output("DEBUG: ".__METHOD__.": EXIT OnAgentlogin");
            }
            return FALSE;
        }        
        
        if ($a->estado_consola != 'logged-in') {
            $a->completarLoginAgente();
            $this->_tuberia->msg_ECCPProcess_AgentLogin(
                $sAgente,
                $params['local_timestamp_received'],
                $a->id_agent);
        } else {
        	if ($this->DEBUG) {
        		$this->_log->output("DEBUG: ".__METHOD__.": AgentLogin($sAgente) duplicado (múltiples colas), ignorando");
                $this->_log->output("DEBUG: ".__METHOD__.": EXIT OnAgentlogin");
        	}
        }
    }

    public function msg_QueueMemberRemoved($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
        
        $a = $this->_listaAgentes->buscar('agentchannel', $params['Location']);

        if (is_null($a)) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": AgentLogin({$params['Location']}) no iniciado por programa, no se hace nada.");
                $this->_log->output("DEBUG: ".__METHOD__.": EXIT OnAgentlogoff");
            }
            return FALSE;
        }        

        if ($a->estado_consola == 'logged-in') {
            $this->_tuberia->msg_ECCPProcess_AgentLogoff(
                $params['Location'],
                $params['local_timestamp_received'], 
                $a->id_agent,
                $a->id_sesion,
                $a->id_audit_break,
                $a->id_audit_hold);
            $a->terminarLoginAgente();
        } else {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": AgentLogin({$params['Location']}) duplicado (múltiples colas), ignorando");
                $this->_log->output("DEBUG: ".__METHOD__.": EXIT OnAgentlogoff");
            }
        }
        
        if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas();
        
        return FALSE;
    }

    public function msg_Join($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
    
        $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid']);
        if (is_null($llamada) && isset($this->_colasEntrantes[$params['Queue']])) {
        	// Llamada de campaña entrante
            $llamada = $this->_listaLlamadas->nuevaLlamada('incoming');
            $llamada->uniqueid = $params['Uniqueid'];
            $llamada->id_queue_call_entry = $this->_colasEntrantes[$params['Queue']]['id_queue_call_entry'];
            if (isset($params['CallerIDNum'])) $llamada->phone = $params['CallerIDNum'];
            if (isset($params['CallerID'])) $llamada->phone = $params['CallerID'];
            $c = $this->_colasEntrantes[$params['Queue']]['campania'];
            if (!is_null($c) && $c->enHorarioVigencia($params['local_timestamp_received'])) {
                $llamada->campania = $c;
            }
        }
        if (!is_null($llamada)) {
            $llamada->llamadaEntraEnCola(
                $params['local_timestamp_received'],
                $params['Channel'],
                $params['Queue']);
        }
        
        return FALSE;
    }
    
    public function msg_Link($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
        
        $llamada = NULL;

        // Recuperar el agente local y el canal remoto
        $regs = NULL;
        $sAgentNum = NULL;
        $sChannel = NULL;
        $sAgentChannel = NULL;
        $sRemChannel = NULL;
        if (preg_match('|^Agent/(\d+)$|', $params['Channel1'], $regs)) {
            $sAgentNum = $regs[1];
            $sChannel = $sAgentChannel = $params['Channel1'];
            $sRemChannel = $params['Channel2'];
        } elseif (  preg_match('|^(SIP/(\d+))\-\w+$|',  $params['Channel1'], $regs) || 
                    preg_match('|^(IAX2/(\d+))\-\w+$|', $params['Channel1'], $regs)) {        
            $sAgentNum   = $regs[2];
            $sChannel    = $regs[1];
            $sRemChannel = $params['Channel2'];
            $sAgentChannel = $params['Channel1'];
        }
        if (preg_match('|^Agent/(\d+)$|', $params['Channel2'], $regs)) {
            $sAgentNum = $regs[1];
            $sChannel = $sAgentChannel = $params['Channel2'];
            $sRemChannel = $params['Channel1'];
        } elseif(   preg_match('|^(SIP/(\d+))\-\w+$|', $params['Channel2'], $regs) || 
                    preg_match('|^(IAX2/(\d+))\-\w+$|', $params['Channel2'], $regs)) {
            $sAgentNum = $regs[2];         
            $sChannel  = $regs[1];                    
            $sRemChannel = $params['Channel1']; // Remote Channel
            $sAgentChannel = $params['Channel2'];
        }

        if (is_null($llamada)) $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid1']);
        if (is_null($llamada)) $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid2']);
        
        if (!is_null($llamada) && !is_null($llamada->timestamp_link) &&
            !is_null($llamada->agente) && $llamada->agente->channel != $sChannel) {
            /* Si la llamada ya ha sido enlazada previamente, y ahora se enlaza 
             * a un canal distinto del agente original, se asume que ha sido 
             * transferida a una extensión fuera de monitoreo, y ya no debe de 
             * monitorearse. Ya que Asterisk no ejecuta un Hangup en este caso,
             * se lo debe simular. 
             */
            $a = $this->_listaAgentes->buscar('agentchannel', $sChannel);
            if (!is_null($a)) {
            	$this->_log->output('WARN: '.__METHOD__.': se ha detectado '.
                    'transferencia a otro agente, pero seguimiento de llamada '.
                    'con múltiples agentes no está (todavía) implementado.');
            } elseif (!is_null($sAgentNum)) {
                $this->_log->output("ERR: ".__METHOD__.": no se ha ".
                    "cargado información de agente $sAgentNum");
                $this->_tuberia->msg_CampaignProcess_requerir_nuevaListaAgentes();
            } else {
                if ($this->DEBUG) {
                	$this->_log->output('DEBUG: '.__METHOD__.': llamada '.
                        'transferida a extensión no monitoreada '.$sChannel.
                        ', se finaliza seguimiento...');
                }
            }
            $llamada->llamadaFinalizaSeguimiento(
                $params['local_timestamp_received'],
                $this->_config['dialer']['llamada_corta']);
            return FALSE;
        }
        
        /* Si no se tiene clave, todavía puede ser llamada agendada que debe 
         * buscarse por nombre de canal. También podría ser una llamada que
         * regresa de Hold, y que ha sido asignado un Uniqueid distinto. Para
         * distinguir los dos casos, se verifica el estado de Hold de la 
         * llamada.
         */
        $sNuevo_Uniqueid = NULL;
        if (is_null($llamada)) {
            $llamada = $this->_listaLlamadas->buscar('actualchannel', $params["Channel1"]);
            if (!is_null($llamada)) $sNuevo_Uniqueid = $params["Uniqueid1"];
        }
        if (is_null($llamada)) {
            $llamada = $this->_listaLlamadas->buscar('actualchannel', $params["Channel2"]);
            if (!is_null($llamada)) $sNuevo_Uniqueid = $params["Uniqueid2"];
        }
        if (!is_null($sNuevo_Uniqueid) && $llamada->uniqueid != $sNuevo_Uniqueid) {
            if ($llamada->status == 'OnHold') {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": identificada llamada que regresa de HOLD ".
                        "{$llamada->channel}, cambiado Uniqueid a {$sNuevo_Uniqueid} ");
                }
                $llamada->llamadaRegresaHold($params['local_timestamp_received'],
                    $sNuevo_Uniqueid, $sAgentChannel);
                return FALSE;
            } elseif (!is_null($llamada->agente_agendado) && $llamada->agente_agendado->channel == $sChannel) {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": identificada llamada agendada".
                        "{$llamada->channel}, cambiado Uniqueid a {$sNuevo_Uniqueid} ");
                }
                $llamada->uniqueid = $sNuevo_Uniqueid;
            } else {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": identificada ".
                        "llamada que comparte un actualchannel={$llamada->actualchannel} ".
                        "pero no regresa de HOLD ni es agendada, se ignora.");
                }
            	$llamada = NULL;
            }
        }
        
        
        if (!is_null($llamada)) {
            // Se tiene la llamada principal monitoreada
            if (!is_null($llamada->timestamp_link)) return FALSE;   // Múltiple link se ignora
            
            $a = $this->_listaAgentes->buscar('agentchannel', $sChannel);
            if (is_null($a)) {
            	$this->_log->output("ERR: no se puede identificar agente asignado a llamada!");
            } else {
                $llamada->llamadaEnlazadaAgente(
                    $params['local_timestamp_received'], $a, $sRemChannel,
                    ($llamada->uniqueid == $params['Uniqueid1']) ? $params['Uniqueid2'] : $params['Uniqueid1'],
                    $sAgentChannel);
            }
        } else {
        	/* El Link de la pata auxiliar con otro canal puede indicar el 
             * ActualChannel requerido para poder manipular la llamada. */
            $sCanalCandidato = NULL;
            if (is_null($llamada)) {
                $llamada = $this->_listaLlamadas->buscar('auxchannel', $params['Uniqueid1']);
                if (!is_null($llamada)) $sCanalCandidato = $params['Channel2'];
            }
            if (is_null($llamada)){
                $llamada = $this->_listaLlamadas->buscar('auxchannel', $params['Uniqueid2']);
                if (!is_null($llamada)) $sCanalCandidato = $params['Channel1'];
            }
            if (!is_null($llamada) && !is_null($sCanalCandidato) && 
                strpos($sCanalCandidato, 'Local/') !== 0) {
            	if (is_null($llamada->actualchannel)) {
                    $llamada->actualchannel = $sCanalCandidato;
                    if ($this->DEBUG) {
            			$this->_log->output('DEBUG: '.__METHOD__.
                            ': capturado canal remoto real: '.$sCanalCandidato);
            		}
            	} elseif ($llamada->actualchannel != $sCanalCandidato) {
                    if (is_null($llamada->timestamp_link)) {
                		$this->_log->output('WARN: '.__METHOD__.': canal remoto en '.
                            'conflicto, anterior '.$llamada->actualchannel.' nuevo '.
                            $sCanalCandidato);
                    } else {
                        if ($this->DEBUG) {
                            $this->_log->output('DEBUG: '.__METHOD__.': canal remoto en '.
                                'conflicto, anterior '.$llamada->actualchannel.' nuevo '.
                                $sCanalCandidato.', se ignora por ser luego de Link.');
                        }
                    }
            	}
            }
        }
        
        return FALSE;
    }
    
    public function msg_Unlink($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
        
        $llamada = NULL;
        if (is_null($llamada)) $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid1']);
        if (is_null($llamada)) $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid2']);

        // Marcar el estado de la llamada que se manda a hold
        if (!is_null($llamada) && $llamada->request_hold) {
        	$llamada->status = 'OnHold';
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": llamada ".
                    ($llamada->uniqueid)." ha sido puesta en HOLD en vez de colgada.");
            }
        }
        return FALSE;
    }
    
    public function msg_Hangup($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
        
        if ($this->_manejarHangupAgentLoginFallido($params)) {
            if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas();
            return FALSE;
        }

        $a = NULL;
        $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid']);
        if (is_null($llamada)) {
        	/* Si la llamada ha sido transferida, la porción que está siguiendo 
             * el marcador todavía está activa, pero transferida a otra extensión.
             * Sin embargo, el agente está ahora libre y recibirá otra llamada. 
             * El hangup de aquí podría ser para la parte de la llamada del 
             * agente. */
            $a = $this->_listaAgentes->buscar('uniqueidlink', $params['Uniqueid']);
            if (!is_null($a)) {
            	$llamada = $a->llamada;
            }
        }

        if (is_null($llamada)) {
            /* Si no se encuentra la llamada, es posible que se haya recibido 
             * Hangup del canal de una llamada que se ha enviado a HOLD, y que 
             * ya ha expirado su espera y debe finalizarse. Entonces OnHold será
             * verdadero, y Channel coincidirá con el ActualChannel de una de 
             * las llamadas. Además debe de localizarse el agente ECCP que mandó
             * a HOLD la llamada y quitarle la pausa. */
            $llamada = $this->_listaLlamadas->buscar('actualchannel', $params['Channel']);
            if (!is_null($llamada) && $llamada->status != 'OnHold') $llamada = NULL;
            if (!is_null($llamada)) {
                if ($this->DEBUG) {
                	$this->_log->output('DEBUG: '.__METHOD__.': llamada colgada mientras estaba en HOLD.');
                }
                $llamada->llamadaRegresaHold($params['local_timestamp_received']);
            }
        }

        if (!is_null($llamada)) {
            $this->_procesarLlamadaColgada($llamada, $params);
        } elseif (is_null($a)) {
            /* No se encuentra la llamada entre las monitoreadas. Puede ocurrir
             * que este sea el Hangup de un canal auxiliar que tiene información
             * de la falla de la llamada */
            $llamada = $this->_listaLlamadas->buscar('auxchannel', $params['Uniqueid']);
            if (!is_null($llamada) && is_null($llamada->timestamp_originateend)) {
                $llamada->AuxChannels[$params['Uniqueid']]['Hangup'] = $params;
                $llamada->registerAuxChannels();
                if ($this->DEBUG) {
                    $this->_log->output(
                        "DEBUG: ".__METHOD__.": Hangup de canal auxiliar de ".
                        "llamada por fallo de Originate para llamada ".
                        $llamada->uniqueid." canal auxiliar ".$params['Uniqueid']);                        
                }
            }
        }
        
        if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas();
        return FALSE;
    }
    
    /* Procesamiento de llamada identificada: params requiere los elementos:
     * local_timestamp_received Uniqueid Channel Cause Cause-txt
     * Esta función también se invoca al cerrar todas las llamadas luego de 
     * reiniciado Asterisk. 
     */
    private function _procesarLlamadaColgada($llamada, $params)
    {
        if (is_null($llamada->timestamp_link)) {
            /* Si se detecta el Hangup antes del OriginateResponse, se marca 
             * la llamada como fallida y se deja de monitorear. */
            if (is_null($llamada->timestamp_originateend) && 
                !is_null($llamada->timestamp_originatestart)) {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": Hangup de llamada por fallo de Originate");                        
                }
                $llamada->llamadaFueOriginada($params['local_timestamp_received'], 
                    $params['Uniqueid'], $params['Channel'], 'Failure',
                    $params['Cause'], $params['Cause-txt']);
            } else {
                $llamada->llamadaFinalizaSeguimiento(
                    $params['local_timestamp_received'],
                    $this->_config['dialer']['llamada_corta']);
            }
        } else {
            if ($llamada->status == 'OnHold') {
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': se ignora Hangup para llamada que se envía a HOLD.');
                }
            } else {
                // Llamada ha sido enlazada al menos una vez
                $llamada->llamadaFinalizaSeguimiento(
                    $params['local_timestamp_received'],
                    $this->_config['dialer']['llamada_corta']);
            }
        }
    }
    
    public function msg_Agentlogin($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }

        // Verificar que este evento corresponde a un Agentlogin iniciado por este programa
        $sAgente = 'Agent/'.$params['Agent'];
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (is_null($a) || $a->estado_consola == 'logged-out') {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": AgentLogin($sAgente) no iniciado por programa, no se hace nada.");
                $this->_log->output("DEBUG: ".__METHOD__.": EXIT OnAgentlogin");
            }
            return FALSE;
        }        
        $a->completarLoginAgente();
        $this->_tuberia->msg_ECCPProcess_AgentLogin(
            $sAgente,
            $params['local_timestamp_received'],
            $a->id_agent);
    }
    
    public function msg_Agentlogoff($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }

        // Verificar que este evento corresponde a un Agentlogin iniciado por este programa
        $sAgente = 'Agent/'.$params['Agent'];
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (is_null($a)) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": AgentLogin($sAgente) no iniciado por programa, no se hace nada.");
                $this->_log->output("DEBUG: ".__METHOD__.": EXIT OnAgentlogoff");
            }
            return FALSE;
        }        
        
        $this->_tuberia->msg_ECCPProcess_AgentLogoff(
            $sAgente,
            $params['local_timestamp_received'], 
            $a->id_agent,
            $a->id_sesion,
            $a->id_audit_break,
            $a->id_audit_hold);
        $a->terminarLoginAgente();
        
        if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas();
        
        return FALSE;
    }

    public function msg_PeerStatus($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }

    	if ($params['PeerStatus'] == 'Unregistered') {
    		// Alguna extensión se ha desregistrado. Verificar si es un agente logoneado
            $a = $this->_listaAgentes->buscar('extension', $params['Peer']);
            if (!is_null($a)) {
            	// La extensión usada para login se ha desregistrado - deslogonear al agente
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.' se detecta desregistro de '.
                        $params['Peer'].' - deslogoneando Agent/'.$a->number);
                }
                $r = $this->_ami->Agentlogoff($a->number);
            }
    	}
    }
}
?>