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

// Número mínimo de muestras para poder confiar en predicciones de marcador
define('MIN_MUESTRAS', 10);

class CampaignProcess extends TuberiaProcess
{
    private $DEBUG = FALSE; // VERDADERO si se activa la depuración

    private $_log;      // Log abierto por framework de demonio
    private $_dsn;      // Cadena que representa el DSN, estilo PDO
    private $_db;       // Conexión a la base de datos, PDO
    private $_ami = NULL;       // Conexión AMI a Asterisk
    private $_configDB; // Objeto de configuración desde la base de datos
    
    // Contadores para actividades ejecutadas regularmente
    private $_iTimestampActualizacion = 0;              // Última actualización remota
    private $_iTimestampUltimaRevisionCampanias = 0;    // Última revisión de campañas
    private $_iTimestampUltimaRevisionConfig = 0;       // Última revisión de configuración

    // Lista de campañas y colas que ya fueron avisadas a AMIEventProcess
    private $_campaniasAvisadas = array(
        'incoming'          =>  array(),
        'outgoing'          =>  array(),
        'incoming_id_queue' =>  array(),
    );

    // VERDADERO si existe tabla asterisk.trunks y se deben buscar troncales allí
    private $_existeTrunksFPBX = FALSE;
    
    /* Caché de información que fue leída para las troncales directas usadas en
     * marcación de campañas salientes desde la base de datos de FreePBX. 
     */
    private $_plantillasMarcado;
    
    // Estimación de la versión de Asterisk que se usa
    private $_asteriskVersion = array(1, 4, 0, 0);

    /* VERDADERO si al momento de verificar actividad en tubería, no habían
     * mensajes pendientes. Sólo cuando se esté ocioso se intentarán verificar
     * nuevas llamadas de la campaña. */
    private $_ociosoSinEventos = TRUE;

    /* Si se setea a VERDADERO, el programa intenta finalizar y no deben 
     * colocarse nuevas llamadas.
     */
    private $_finalizandoPrograma = FALSE;

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
    	$this->_log = $oMainLog;
        $this->_multiplex = new MultiplexServer(NULL, $this->_log);
        $this->_tuberia->registrarMultiplexHijo($this->_multiplex);
        $this->_tuberia->setLog($this->_log);

        // Interpretar la configuración del demonio
        $this->_dsn = $this->_interpretarConfiguracion($infoConfig);
        if (!$this->_iniciarConexionDB()) return FALSE;
        
        // Leer el resto de la configuración desde la base de datos
        try {
            $this->_configDB = new ConfigDB($this->_db, $this->_log);
        } catch (PDOException $e) {
            $this->_log->output("FATAL: no se puede leer configuración DB - ".$e->getMessage());
            return FALSE;
        }

        // Recuperarse de cualquier fin anormal anterior
        try {
            $this->_db->query('DELETE FROM current_calls WHERE 1');
            $this->_db->query('DELETE FROM current_call_entry WHERE 1');
        } catch (PDOException $e) {
            $this->_log->output("FATAL: error al limpiar tablas current_calls - ".$e->getMessage());
        	return FALSE;
        }        

        // Detectar capacidades de FreePBX y de call_center
        $this->_detectarTablaTrunksFPBX();

        // Iniciar la conexión Asterisk
        if (!$this->_iniciarConexionAMI()) return FALSE;
        
        // Registro de manejadores de eventos desde CampaignProcess
        foreach (array('requerir_nuevaListaAgentes', 'sqlinsertcalls', 
            'sqlupdatecalls', 'sqlinsertcurrentcalls', 'sqldeletecurrentcalls',
            'sqlupdatecurrentcalls', 'sqlupdatestatcampaign',
            'actualizarCanalRemoto', 'finalsql', 'verificarFinLlamadasAgendables') as $k)
            $this->_tuberia->registrarManejador('AMIEventProcess', $k, array($this, "msg_$k"));

        // Registro de manejadores de eventos desde HubProcess
        $this->_tuberia->registrarManejador('HubProcess', 'finalizando', array($this, "msg_finalizando"));

        $this->DEBUG = $this->_configDB->dialer_debug;

        // Informar a AMIEventProcess la configuración de Asterisk
        $this->_tuberia->AMIEventProcess_informarCredencialesAsterisk(array(
            'asterisk'  =>  array(
                'asthost'           =>  $this->_configDB->asterisk_asthost,
                'astuser'           =>  $this->_configDB->asterisk_astuser,
                'astpass'           =>  $this->_configDB->asterisk_astpass,
                'duracion_sesion'   =>  $this->_configDB->asterisk_duracion_sesion,
            ),
            'dialer'    =>  array(
                'llamada_corta'     =>  $this->_configDB->dialer_llamada_corta,
                'tiempo_contestar'  =>  $this->_configDB->dialer_tiempo_contestar,
                'debug'             =>  $this->_configDB->dialer_debug,
                'allevents'         =>  $this->_configDB->dialer_allevents,
            ),
        ));

        return TRUE;
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
    
    /**
     * Procedimiento que detecta la existencia de la tabla asterisk.trunks. Si
     * existe, la información de troncales está almacenada allí, y no en la
     * tabla globals. Esto se cumple en versiones recientes de FreePBX.
     * 
     * @return void
     */
    private function _detectarTablaTrunksFPBX()
    {
        $dbConn = $this->_abrirConexionFreePBX();
        if (is_null($dbConn)) return;

        try {
        	$recordset = $dbConn->prepare("SHOW TABLES LIKE 'trunks'");
            $recordset->execute();
            $item = $recordset->fetch(PDO::FETCH_COLUMN, 0);
            $recordset->closeCursor();
            if ($item != 'trunks') {
                // Probablemente error de que asterisk.trunks no existe
            	$this->_log->output("INFO: tabla asterisk.trunks no existe, se asume FreePBX viejo.");
            } else {
                // asterisk.trunks existe
                $this->_log->output("INFO: tabla asterisk.trunks sí existe, se asume FreePBX reciente.");
                $this->_existeTrunksFPBX = TRUE;
            }
        } catch (PDOException $e) {
        	$this->_log->output("ERR: al consultar tabla de troncales: ".implode(' - ', $e->errorInfo));
        }
        $dbConn = NULL;
    }

    public function procedimientoDemonio()
    {
        // Verificar posible desconexión de la base de datos
        if (is_null($this->_db)) {
            $this->_log->output('INFO: intentando volver a abrir conexión a DB...');
            if (!$this->_iniciarConexionDB()) {
                $this->_log->output('ERR: no se puede restaurar conexión a DB, se espera...');
                usleep(5000000);
            } else {
                $this->_log->output('INFO: conexión a DB restaurada, se reinicia operación normal.');
                $this->_configDB->setDBConn($this->_db);
            }
        }

        // Verificar si la conexión AMI sigue siendo válida
        if (!is_null($this->_ami) && is_null($this->_ami->sKey)) $this->_ami = NULL;
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
                
                /* TODO: si el Asterisk ha sido reiniciado, probablemente ha
                 * olvidado la totalidad de las llamadas en curso, así como los
                 * agentes que estaban logoneados. Es necesario implementar una
                 * verificación de si los agentes están logoneados, y resetear
                 * todo el estado del marcador si la información interna del
                 * marcador está desactualizada. */
            }
        }

        // Actualizar la generación de llamadas para las campañas
        if (!is_null($this->_db)) {
            try {
                if (!$this->_finalizandoPrograma) {
                    // Verificar si se ha cambiado la configuración
                    $this->_verificarCambioConfiguracion();
    
                    if ($this->_ociosoSinEventos) {
                        if (!is_null($this->_ami)) $this->_actualizarCampanias();
                
                        // Actualizar la información remota en AMIClientConn
                        $this->_actualizarInformacionRemota();
                    }
                }
        
                // Rutear todos los mensajes pendientes entre tareas
                $this->_ociosoSinEventos = !$this->_multiplex->procesarPaquetes();
                $this->_multiplex->procesarActividad($this->_ociosoSinEventos ? 1 : 0);
            } catch (PDOException $e) {
                $this->_log->output('ERR: '.__METHOD__.
                    ': no se puede realizar operación de base de datos: '.
                    implode(' - ', $e->errorInfo));
                $this->_log->output("ERR: traza de pila: \n".$e->getTraceAsString());
                if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 2006) {
                    // Códigos correspondientes a pérdida de conexión de base de datos
                    $this->_log->output('WARN: '.__METHOD__.
                        ': conexión a DB parece ser inválida, se cierra...');
                    $this->_db = NULL;
                }
            }
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

            // CampaignProcess no tiene manejadores de eventos AMI

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
                foreach ($listaVarCambiadas as $k) {
                	if (in_array($k, array('asterisk_asthost', 'asterisk_astuser', 'asterisk_astpass'))) {
                		$this->_tuberia->msg_AMIEventProcess_actualizarConfig(
                            'asterisk_cred', array(
                                $this->_configDB->asterisk_asthost,
                                $this->_configDB->asterisk_astuser,
                                $this->_configDB->asterisk_astpass,
                            ));
                	} elseif (in_array($k, array('asterisk_duracion_sesion', 
                        'dialer_llamada_corta', 'dialer_tiempo_contestar', 
                        'dialer_debug', 'dialer_allevents'))) {
                        $this->_tuberia->msg_AMIEventProcess_actualizarConfig($k, $this->_configDB->$k);
                    }
                }
                
                if (in_array('dialer_debug', $listaVarCambiadas))
                    $this->DEBUG = $this->_configDB->dialer_debug;
                $this->_configDB->limpiarCambios();
            }
            $this->_iTimestampUltimaRevisionConfig = $iTimestamp;
        }
    }
    
    /* Mandar a los otros procedimientos la información que no pueden leer 
     * directamente porque no tienen conexión de base de datos. */
    private function _actualizarInformacionRemota()
    {
    	$iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampActualizacion >= 5 * 60) {
            //$this->_log->output('INFO: actualizando información remota...');

            $this->_actualizarInformacionRemota_agentes();

            $this->_iTimestampActualizacion = $iTimestamp;
        }
    }

    // Mandar a AMIEventProcess una lista actualizada de los agentes activos
    private function _actualizarInformacionRemota_agentes()
    {
    	// El ORDER BY del query garantiza que estatus A aparece antes que I
        $recordset = $this->_db->query(
            'SELECT id, number, name, estatus FROM agent ORDER BY number, estatus');
        $lista = array(); $listaNum = array();
        foreach ($recordset as $tupla) {
            if (!in_array($tupla['number'], $listaNum)) {
            	$lista[] = array(
                    'id'        =>  $tupla['id'],
                    'number'    =>  $tupla['number'],
                    'name'      =>  $tupla['name'],
                    'estatus'   =>  $tupla['estatus'],
                );
                $listaNum[] = $tupla['number'];
            }
        }
        
        // Mandar el recordset a AMIEventProcess como un mensaje
        $this->_tuberia->msg_AMIEventProcess_nuevaListaAgentes($lista);
    }

    private function _actualizarCampanias()
    {
        // Revisar las campañas cada 3 segundos
        $iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampUltimaRevisionCampanias > 3) {
            $sFecha = date('Y-m-d', $iTimestamp);
            $sHora = date('H:i:s', $iTimestamp);
            $listaCampanias = array(
                'incoming'  =>  array(),
                'outgoing'  =>  array(),
            );

            // Leer la lista de campañas salientes que entran en actividad ahora
            $sPeticionCampanias = <<<PETICION_CAMPANIAS_SALIENTES
SELECT id, name, trunk, context, queue, max_canales, num_completadas,
    promedio, desviacion, retries, datetime_init, datetime_end, daytime_init, 
    daytime_end
FROM campaign 
WHERE datetime_init <= ? AND datetime_end >= ? AND estatus = "A"
    AND (
            (daytime_init < daytime_end AND daytime_init <= ? AND daytime_end >= ?)
        OR  (daytime_init > daytime_end AND (daytime_init <= ? OR daytime_end >= ?)
    )
)
PETICION_CAMPANIAS_SALIENTES;
            $recordset = $this->_db->prepare($sPeticionCampanias);
            $recordset->execute(array($sFecha, $sFecha, $sHora, $sHora, $sHora, $sHora));
            $recordset->setFetchMode(PDO::FETCH_ASSOC);
            foreach ($recordset as $tupla) {
            	$listaCampanias['outgoing'][] = $tupla;
            }
            
            // Leer la lista de campañas entrantes que entran en actividad ahora
            $sPeticionCampanias = <<<PETICION_CAMPANIAS_ENTRANTES
SELECT c.id, c.name, c.id_queue_call_entry, q.queue, c.datetime_init, c.datetime_end, c.daytime_init, 
    c.daytime_end
FROM campaign_entry c, queue_call_entry q
WHERE q.id = c.id_queue_call_entry AND c.datetime_init <= ? 
    AND c.datetime_end >= ? AND c.estatus = "A"
    AND (
            (c.daytime_init < c.daytime_end AND c.daytime_init <= ? AND c.daytime_end > ?)
        OR  (c.daytime_init > c.daytime_end AND (? < c.daytime_init OR c.daytime_end < ?)
    )
)
PETICION_CAMPANIAS_ENTRANTES;
            $recordset = $this->_db->prepare($sPeticionCampanias);
            $recordset->execute(array($sFecha, $sFecha, $sHora, $sHora, $sHora, $sHora));
            $recordset->setFetchMode(PDO::FETCH_ASSOC);
            foreach ($recordset as $tupla) {
                $listaCampanias['incoming'][] = $tupla;
            }
            
            // Construir lista de campañas y colas que no han sido todavía avisadas
            $listaCampaniasAvisar = array(
                'incoming'              =>  array(),    // Nuevas campañas entrantes
                'outgoing'              =>  array(),    // Nuevas campañas salientes
                'incoming_queue_new'    =>  array(),    // Nuevas colas definidas como entrantes
                'incoming_queue_old'    =>  array(),    // Colas que ya no están definidas como entrantes
            );
            foreach ($listaCampanias as $t => $l) {
                $listaIdx = array();
                foreach ($l as $tupla) {
                    $listaIdx[] = $tupla['id'];
                	if (!in_array($tupla['id'], $this->_campaniasAvisadas[$t])) {
                		$listaCampaniasAvisar[$t][$tupla['id']] = $tupla;
                	}
                }
                $this->_campaniasAvisadas[$t] = $listaIdx;
            }

            // Leer la lista de colas entrantes que pueden o no tener una campaña
            $listaColas = array();
            foreach (
                $this->_db->query('SELECT id, queue FROM queue_call_entry WHERE estatus = "A"') 
                as $tupla) {
                $listaColas[$tupla['id']] = array(
                    'id'    =>  $tupla['id'],
                    'queue' =>  $tupla['queue'],
                );
            }
            $listaIdColas = array_keys($listaColas);
            foreach (array_diff($listaIdColas, $this->_campaniasAvisadas['incoming_id_queue']) as $id)
                $listaCampaniasAvisar['incoming_queue_new'][$id] = $listaColas[$id];
            foreach (array_diff($this->_campaniasAvisadas['incoming_id_queue'], $listaIdColas) as $id)
                $listaCampaniasAvisar['incoming_queue_old'][$id] = $listaColas[$id];
            if (count($listaCampaniasAvisar['incoming_queue_new']) != 0 || 
                count($listaCampaniasAvisar['incoming_queue_old']) != 0)
                $this->_campaniasAvisadas['incoming_id_queue'] = $listaIdColas;

            // Mandar a avisar a AMIEventProcess sobre las campañas y colas activas
            if (!(count($listaCampaniasAvisar['incoming']) == 0 &&
                count($listaCampaniasAvisar['outgoing']) == 0 && 
                count($listaCampaniasAvisar['incoming_queue_new']) == 0 &&
                count($listaCampaniasAvisar['incoming_queue_old']) == 0))
                $this->_tuberia->AMIEventProcess_nuevasCampanias($listaCampaniasAvisar);

            // Generar las llamadas para todas las campañas salientes activas
            foreach ($listaCampanias['outgoing'] as $tuplaCampania) {
            	$this->_actualizarLlamadasCampania($tuplaCampania);
            }

            $this->_iTimestampUltimaRevisionCampanias = $iTimestamp;
        }
    }

    private function _actualizarLlamadasCampania($infoCampania)
    {
        $iTimeoutOriginate = $this->_configDB->dialer_timeout_originate;
        if (is_null($iTimeoutOriginate) || $iTimeoutOriginate <= 0)
            $iTimeoutOriginate = NULL;
        else $iTimeoutOriginate *= 1000; // convertir a milisegundos

        $iNumLlamadasColocar = 0;

        // Construir patrón de marcado a partir de trunk de campaña
        $datosTrunk = $this->_construirPlantillaMarcado($infoCampania['trunk']);
        if (is_null($datosTrunk)) {
            $this->_log->output("ERR: no se puede construir plantilla de marcado a partir de trunk '{$infoCampania['trunk']}'!");
            $this->_log->output("ERR: Revise los mensajes previos. Si el problema es un tipo de trunk no manejado, ".
                "se requiere informar este tipo de trunk y/o actualizar su versión de CallCenter");
            return FALSE;
        }

        // Leer cuántas llamadas (como máximo) se pueden hacer por campaña
        $iNumLlamadasColocar = $infoCampania['max_canales'];
        if (!is_null($iNumLlamadasColocar) && $iNumLlamadasColocar <= 0) return FALSE;

        $oPredictor = new Predictivo($this->_ami);

        // Listar todas las llamadas agendables para la campaña
        $listaLlamadasAgendadas = $this->_actualizarLlamadasAgendables($infoCampania, $datosTrunk);

        // Parámetros requeridos para predicción de colocación de llamadas
        $oPredictor->setPromedioDuracion($infoCampania['queue'], $infoCampania['promedio']);
        $oPredictor->setDesviacionDuracion($infoCampania['queue'], $infoCampania['desviacion']);
        $oPredictor->setProbabilidadAtencion($infoCampania['queue'], $this->_configDB->dialer_qos);
        
        // Calcular el tiempo que se tarda desde Originate hasta Link con agente.
        $oPredictor->setTiempoContestar($infoCampania['queue'], $this->_leerTiempoContestar($infoCampania['id']));

        // Averiguar cuantas llamadas se pueden hacer (por predicción), y tomar
        // el menor valor de entre máx campaña y predictivo.
        if ($this->DEBUG) {
        	$this->_log->output('DEBUG: '.__METHOD__.' verificando agentes libres...');
        }
        $resumenPrediccion = $oPredictor->predecirNumeroLlamadas(
            $infoCampania['queue'], 
            $this->_configDB->dialer_predictivo && ($infoCampania['num_completadas'] >= MIN_MUESTRAS));
        if ($this->DEBUG) {
        	$this->_log->output('DEBUG: '.__METHOD__." (campania {$infoCampania['id']} ".
                "cola {$infoCampania['queue']}): resumen de predicción:\n".
                    "\tagentes libres.........: {$resumenPrediccion[0]}\n".
                    "\tagentes por desocuparse: {$resumenPrediccion[1]}\n".
                    "\tclientes en espera.....: {$resumenPrediccion[2]}");
        }
        $iMaxPredecidos = $resumenPrediccion[0] + $resumenPrediccion[1] - $resumenPrediccion[2];
        if ($iMaxPredecidos < 0) $iMaxPredecidos = 0;
        if (is_null($iNumLlamadasColocar) || $iNumLlamadasColocar > $iMaxPredecidos)
            $iNumLlamadasColocar = $iMaxPredecidos;
        
        // TODO: colocar código de detección de conflicto de agentes
        
        /* El valor de llamadas predichas no toma en cuenta las llamadas que han
         * sido generadas pero todavía no se recibe su OriginateResponse. Para
         * evitar sobrecolocar mientras las primeras llamadas esperan ser
         * contestadas, se cuentan tales llamadas y se resta. */
        $iNumEsperanRespuesta = count($listaLlamadasAgendadas) + 
            $this->_contarLlamadasEsperandoRespuesta($infoCampania['queue']);

        if ($iNumLlamadasColocar > $iNumEsperanRespuesta)
            $iNumLlamadasColocar -= $iNumEsperanRespuesta;
        else $iNumLlamadasColocar = 0;

        if (count($listaLlamadasAgendadas) <= 0 && $iNumLlamadasColocar <= 0) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__." (campania {$infoCampania['id']} cola ".
                    "{$infoCampania['queue']}) no hay agentes libres ni a punto ".
                    "de desocuparse!");
            }
            return FALSE;   
        }

        if ($this->DEBUG) {
            $this->_log->output("DEBUG: ".__METHOD__." (campania {$infoCampania['id']} cola ".
                "{$infoCampania['queue']}) se pueden colocar un máximo de ".
                "$iNumLlamadasColocar llamadas...");   
        }

        if ($iNumLlamadasColocar > 0 && $this->_configDB->dialer_overcommit) {
            // Para compensar por falla de llamadas, se intenta colocar más de la cuenta. El porcentaje
            // de llamadas a sobre-colocar se determina a partir de la historia pasada de la campaña.
            $iVentanaHistoria = 60 * 30; // TODO: se puede autocalcular?
            $sPeticionASR = 
                'SELECT COUNT(*) AS total, SUM(IF(status = "Failure" OR status = "NoAnswer", 0, 1)) AS exito ' .
                'FROM calls ' .
                'WHERE id_campaign = ? AND status IS NOT NULL ' .
                    'AND status <> "Placing" ' .
                    'AND fecha_llamada IS NOT NULL ' .
                    'AND fecha_llamada >= ?';
            $recordset = $this->_db->prepare($sPeticionASR);
            $recordset->execute(array($infoCampania['id'], date('Y-m-d H:i:s', time() - $iVentanaHistoria)));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();

            // Sólo considerar para más de 10 llamadas colocadas durante ventana
            if ($tupla['total'] >= 10 && $tupla['exito'] > 0) {
                $ASR = $tupla['exito'] / $tupla['total'];
                $ASR_safe = $ASR;
                if ($ASR_safe < 0.20) $ASR_safe = 0.20;
                $iNumLlamadasColocar = (int)round($iNumLlamadasColocar / $ASR_safe); 
                if ($this->DEBUG) {
                    $this->_log->output(
                        "DEBUG: (campania {$infoCampania['id']} cola {$infoCampania['queue']}) ".
                        "en los últimos $iVentanaHistoria seg. tuvieron éxito " .
                        "{$tupla['exito']} de {$tupla['total']} llamadas colocadas (ASR=".(sprintf('%.2f', $ASR * 100))."%). Se colocan " .
                        "$iNumLlamadasColocar para compensar.");
                }
            }
        }


        // Leer tantas llamadas como fueron elegidas. Sólo se leen números con
        // status == NULL y bandera desactivada
        $listaLlamadas = $listaLlamadasAgendadas;
        $iNumTotalLlamadas = count($listaLlamadas);
        if ($iNumLlamadasColocar > 0) {
            $sFechaSys = date('Y-m-d');
            $sHoraSys = date('H:i:s');
            $sPeticionLlamadas = <<<PETICION_LLAMADAS
(SELECT 1 AS dummy_priority, id_campaign, id, phone, date_init AS dummy_date_init,
    time_init AS dummy_time_init, date_end AS dummy_date_end,
    time_end AS dummy_time_end, retries
FROM calls
WHERE id_campaign = ?
    AND (status IS NULL 
        OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold")) 
    AND retries < ?
    AND dnc = 0
    AND (? BETWEEN date_init AND date_end AND ? BETWEEN time_init AND time_end)
    AND agent IS NULL)
UNION
(SELECT 2 AS dummy_priority, id_campaign, id, phone, date_init AS dummy_date_init,
    time_init AS dummy_time_init, date_end AS dummy_date_end,
    time_end AS dummy_time_end, retries
FROM calls
WHERE id_campaign = ?
    AND (status IS NULL 
        OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold")) 
    AND retries < ?
    AND dnc = 0
    AND date_init IS NULL AND date_end IS NULL AND time_init IS NULL AND time_end IS NULL
    AND agent IS NULL)
ORDER BY dummy_priority, retries, dummy_date_end, dummy_time_end, dummy_date_init, dummy_time_init, id
LIMIT 0,?
PETICION_LLAMADAS;
            $recordset = $this->_db->prepare($sPeticionLlamadas);
            $recordset->execute(array(
                $infoCampania['id'], 
                $infoCampania['retries'],
                $sFechaSys, $sHoraSys,
                $infoCampania['id'], 
                $infoCampania['retries'],
                $iNumLlamadasColocar));
            $recordset->setFetchMode(PDO::FETCH_ASSOC);
            $pid = posix_getpid();
            foreach ($recordset as $tupla) {
                $iNumTotalLlamadas++;
                $sKey = sprintf('%d-%d-%d', $pid, $infoCampania['id'], $tupla['id']);
                $sCanalTrunk = str_replace('$OUTNUM$', $tupla['phone'], $datosTrunk['TRUNK']);
    
                /* Para poder monitorear el evento Onnewchannel, se depende de 
                 * la cadena de marcado para identificar cuál de todos los eventos
                 * es el correcto. Si una llamada generada produce la misma cadena
                 * de marcado que una que ya se monitorea, o que otra en la misma
                 * lista, ocurrirán confusiones entre los eventos. Se filtran las
                 * llamadas que tengan cadenas de marcado repetidas. */
                if (!isset($listaLlamadas[$tupla['phone']])) {
                    // Llamada no repetida, se procesa normalmente
                    $tupla['actionid'] = $sKey;
                    $tupla['dialstring'] = $sCanalTrunk;
                    $tupla['agent'] = NULL; // Marcar la llamada como no agendada
                	$listaLlamadas[$tupla['phone']] = $tupla;
                } else {
                	// Se ha encontrado en la lectura un número de teléfono repetido
                    $this->_log->output("INFO: se ignora llamada $sKey con DialString ".
                        "$sCanalTrunk - mismo DialString usado por llamada a punto de originar.");
                }
            }
        }
        
        if ($iNumTotalLlamadas <= 0) {
            /* Debido a que ahora las llamadas pueden agendarse a una hora 
             * específica, puede ocurrir que la lista de llamadas por realizar 
             * esté vacía porque hay llamadas agendadas, pero fuera del horario
             * indicado por la hora del sistema. Si la cuenta del query de abajo
             * devuelve al menos una llamada, se interrumpe el procesamiento y 
             * se sale. 
             */
            $sPeticionTotal =
                'SELECT COUNT(*) AS N FROM calls '.
                'WHERE id_campaign = ? '.
                    'AND (status IS NULL OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold")) '.
                    'AND retries < ? '.
                    'AND dnc = 0';
            $recordset = $this->_db->prepare($sPeticionTotal);
            $recordset->execute(array($infoCampania['id'], $infoCampania['retries']));
            $iNumTotal = $recordset->fetch(PDO::FETCH_COLUMN, 0);
            $recordset->closeCursor();
            if (!is_null($iNumTotal) && $iNumTotal > 0) {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__." (campania {$infoCampania['id']} ".
                        "cola {$infoCampania['queue']}) no hay llamadas a ".
                        "colocar; $iNumTotal llamadas agendadas pero fuera de ".
                        "horario.");
                }
                return FALSE;
            }
        }

        /* Verificar si las llamadas están colocadas en la lista de Do Not Call. 
         * Esto puede ocurrir incluso si la bandera dnc es 0, si la lista se 
         * actualiza luego de cargar la lista de llamadas salientes. */
        $recordset = $this->_db->prepare(
            'SELECT COUNT(*) FROM dont_call WHERE caller_id = ? AND status = "A"');
        $sth = $this->_db->prepare(
            'UPDATE calls SET dnc = 1 WHERE id_campaign = ? AND id = ?');
        foreach (array_keys($listaLlamadas) as $k) {
            $recordset->execute(array($k));
            $iNumDNC = $recordset->fetch(PDO::FETCH_COLUMN, 0);
            $recordset->closeCursor();
            if ($iNumDNC > 0) {
            	if ($this->DEBUG) {
            		$this->_log->output('DEBUG: '.__METHOD__." (campania {$infoCampania['id']} ".
                        "número $k encontrado en lista DNC, no se marcará.");
            	}
                $sth->execute(array($infoCampania['id'], $tupla['id']));
                unset($listaLlamadas[$k]);
            }
        }
        
        /* Mandar los teléfonos a punto de marcar a AMIEventProcess. Se espera
         * de vuelta una lista de los números que ya están repetidos y en proceso
         * de marcado. */
        if (count($listaLlamadas) > 0) {
            $listaKeyRepetidos = $this->_tuberia->AMIEventProcess_nuevasLlamadasMarcar($listaLlamadas);
            foreach ($listaKeyRepetidos as $k) {
            	$sKey = $listaLlamadas[$k]['actionid'];
                $sCanalTrunk = $listaLlamadas[$k]['dialstring'];
                $this->_log->output("INFO: se ignora llamada $sKey con DialString ".
                    "$sCanalTrunk - mismo DialString usado por llamada monitoreada.");
                unset($listaLlamadas[$k]);
            }
        }
        
        // Generar realmente todas las llamadas leídas
        $sPeticionLlamadaColocada = <<<SQL_LLAMADA_COLOCADA
UPDATE calls SET status = ?, datetime_originate = ?, fecha_llamada = NULL, 
    datetime_entry_queue = NULL, start_time = NULL, end_time = NULL, 
    duration_wait = NULL, duration = NULL, failure_cause = NULL, 
    failure_cause_txt = NULL, uniqueid = NULL, id_agent = NULL
WHERE id_campaign = ? AND id = ?
SQL_LLAMADA_COLOCADA;
        $sth = $this->_db->prepare($sPeticionLlamadaColocada);
        $sPeticionLlamadaFallida = <<<SQL_LLAMADA_COLOCADA
UPDATE calls SET status = ?, datetime_originate = ?, fecha_llamada = NULL, 
    datetime_entry_queue = NULL, start_time = NULL, end_time = NULL, 
    duration_wait = NULL, duration = NULL, failure_cause = NULL, 
    failure_cause_txt = NULL, uniqueid = NULL, id_agent = NULL, 
    retries = retries + 1
WHERE id_campaign = ? AND id = ?
SQL_LLAMADA_COLOCADA;
        $sth_fallo = $this->_db->prepare($sPeticionLlamadaFallida);
        foreach ($listaLlamadas as $tupla) {
            $listaVars = array(
                'ID_CAMPAIGN'   =>  $infoCampania['id'],
                'ID_CALL'       =>  $tupla['id'],
                'NUMBER'        =>  $tupla['phone'],
                'QUEUE'         =>  $infoCampania['queue'],
                'CONTEXT'       =>  $infoCampania['context'],
            );
            if (!is_null($tupla['agent'])) {
            	$listaVars['AGENTCHANNEL'] = $tupla['agent'];
                $listaVars['QUEUE_MONITOR_FORMAT'] = $this->_formatoGrabacionCola($infoCampania['queue']);
            }
            $sCadenaVar = $this->_construirCadenaVariables($listaVars);
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__." generando llamada\n".
                    "\tClave....... {$tupla['actionid']}\n" .
                    "\tAgente...... ".(is_null($tupla['agent']) ? '(ninguno)' : $tupla['agent'])."\n" .
                    "\tDestino..... {$tupla['phone']}\n" .
                    "\tCola........ {$infoCampania['queue']}\n" .
                    "\tContexto.... {$infoCampania['context']}\n" .
                    "\tVar. Contexto $sCadenaVar\n" .
                    "\tTrunk....... ".(is_null($infoCampania['trunk']) ? '(por plan de marcado)' : $infoCampania['trunk'])."\n" .
                    "\tPlantilla... ".$datosTrunk['TRUNK']."\n" .
                    "\tCaller ID... ".(isset($datosTrunk['CID']) ? $datosTrunk['CID'] : "(no definido)")."\n".
                    "\tCadena de marcado... {$tupla['dialstring']}\n".
                    "\tTimeout marcado..... ".(is_null($iTimeoutOriginate) ? '(por omisión)' : $iTimeoutOriginate.' ms.'));
            }
            if (is_null($tupla['agent'])) {
                $resultado = $this->_ami->Originate(
                    $tupla['dialstring'], $infoCampania['queue'], $infoCampania['context'], 1,
                    NULL, NULL, $iTimeoutOriginate, 
                    (isset($datosTrunk['CID']) ? $datosTrunk['CID'] : NULL), 
                    $sCadenaVar,
                    NULL, 
                    TRUE, $tupla['actionid']);
            } else {
                // Este código asume Agent/9000
                $sExten = $tupla['agent']; $regs = NULL;
                if (preg_match('|^Agent/(\d+)|', $sExten, $regs))
                    $sExten = $regs[1];
                $resultado = $this->_ami->Originate(
                    $tupla['dialstring'], $sExten, 'llamada_agendada', 1,
                    NULL, NULL, $iTimeoutOriginate, 
                    (isset($datosTrunk['CID']) ? $datosTrunk['CID'] : NULL), 
                    $sCadenaVar,
                    NULL, 
                    TRUE, $tupla['actionid']);
            }
            $iTimestampInicioOriginate = time();

            if (!is_array($resultado) || count($resultado) == 0) {
                $this->_log->output("ERR: problema al enviar Originate a Asterisk");
                $this->_iniciarConexionAMI();
            }
            
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__." llamada generada: {$tupla['actionid']} {$tupla['dialstring']}");
            }
                        
            if ($resultado['Response'] == 'Success') {                
                $this->_tuberia->msg_AMIEventProcess_avisoInicioOriginate(
                    $tupla['actionid'], $iTimestampInicioOriginate);
                $sth->execute(array('Placing', date('Y-m-d H:i:s', $iTimestampInicioOriginate), 
                    $infoCampania['id'], $tupla['id']));
            } else {
                $this->_log->output(
                    "ERR: (campania {$infoCampania['id']} cola {$infoCampania['queue']}) ".
                    "no se puede llamar a número - ".print_r($resultado, TRUE));
                $sth_fallo->execute(array('Failure', date('Y-m-d H:i:s', $iTimestampInicioOriginate), 
                    $infoCampania['id'], $tupla['id']));
                $this->_tuberia->msg_AMIEventProcess_avisoInicioOriginate($tupla['actionid'], NULL);
            }
            
            // Notificar el progreso de la llamada
            $this->_tuberia->msg_ECCPProcess_notificarProgresoLlamada(array(
                'datetime_entry'    =>  date('Y-m-d H:i:s', $iTimestampInicioOriginate),
                'new_status'        =>  ($resultado['Response'] == 'Success') ? 'Placing' : 'Failure',
                'id_call_outgoing'  =>  $tupla['id'],
                'retry'             =>  (is_null($tupla['retries']) ? 0 : $tupla['retries']) + 1,
                'trunk'             =>  $infoCampania['trunk'],
            ));
        }
        
        /* Si se llega a este punto, se presume que, con agentes disponibles, y 
         * campaña activa, se terminaron las llamadas. Por lo tanto la campaña 
         * ya ha terminado */
        if ($iNumLlamadasColocar > 0 && $iNumTotalLlamadas <= 0) {
        	$this->_log->output('INFO: marcando campaña como finalizada: '.$infoCampania['id']);
            $sth = $this->_db->prepare('UPDATE campaign SET estatus = "T" WHERE id = ?');
            $sth->execute(array($infoCampania['id']));
        }
    }

    /* Leer el formato de grabación de la cola indicada por el parámetro, la cual
     * está indicada en queues_additional.conf */
    private function _formatoGrabacionCola($sCola)
    {
    	$sColaActual = NULL;
        foreach (file('/etc/asterisk/queues_additional.conf') as $s) {
    		$regs = NULL;
            if (preg_match('/^\[(\d+)\]/', $s, $regs)) {
    			$sColaActual = $regs[1];
    		} elseif ($sColaActual == $sCola && preg_match('/^monitor-format=(\w+)/', $s, $regs)) {
    			return $regs[1];
    		}
    	}
        return NULL;
    }

    private function _actualizarLlamadasAgendables($infoCampania, $datosTrunk)
    {
    	$listaAgentesAgendados = $this->_listarAgentesAgendadosReserva($infoCampania['id']);
        if (count($listaAgentesAgendados) <= 0) return array();
        
        if ($this->DEBUG) {
        	$this->_log->output('DEBUG: '.__METHOD__.': lista de agentes con llamadas agendadas: '.
                print_r($listaAgentesAgendados, 1));
        }
        $resultado = $this->_tuberia->AMIEventProcess_agentesAgendables($listaAgentesAgendados);
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.': resultado de agentesAgendables: '.
                print_r($resultado, 1));
        }
        
        // Poner en pausa a todos los agentes que no estén en pausa ya
        foreach ($resultado['entraron'] as $sAgente) {
        	if ($this->DEBUG) {
        		$this->_log->output('DEBUG: '.__METHOD__.': pausando agente '.$sAgente);
        	}
            $r = $this->_ami->QueuePause(NULL /*$infoCampania['queue']*/, $sAgente, 'true');
            if ($this->DEBUG) {
                $this->_log->output('DEBUG: '.__METHOD__.': resultado de pausa es: '.print_r($r, 1));
                /*
                $this->_log->output("DEBUG: (campania $infoCampania->id cola $infoCampania->queue) " .
                    "resultado de QueuePause($infoCampania->queue, \"Agent/$idAgente\", 'true') : ".
                    print_r($resultado, TRUE));
                */
            }
        }
        
        // Leer una llamada para cada agente que se puede usar en agendamiento
        $listaLlamadas = array();
        $pid = posix_getpid();
        foreach ($resultado['ociosos'] as $sAgente) {
        	$tupla = $this->_listarLlamadasAgendables($infoCampania['id'], $sAgente);
            if (is_array($tupla)) {
                $tupla['actionid'] = sprintf('%d-%d-%d', $pid, $infoCampania['id'], $tupla['id']);
                $tupla['dialstring'] = str_replace('$OUTNUM$', $tupla['phone'], $datosTrunk['TRUNK']);
                
                /* Para poder monitorear el evento Onnewchannel, se depende de la 
                 * cadena de marcado para identificar cuál de todos los eventos es 
                 * el correcto. Si una llamada generada produce la misma cadena de 
                 * marcado que una que ya se monitorea, o que otra en la misma 
                 * lista, ocurrirán confusiones entre los eventos. Se filtran las
                 * llamadas que tengan cadenas de marcado repetidas. */
                
                if (!isset($listaLlamadas[$tupla['phone']])) {
                    // Llamada no repetida, se procesa normalmente
                    $listaLlamadas[$tupla['phone']] = $tupla;
                } else {
                    // Se ha encontrado en la lectura un número de teléfono repetido
                    $this->_log->output("INFO: se ignora llamada {$tupla['actionid']} ".
                        "con DialString {$tupla['dialstring']} - mismo DialString ".
                        "usado por llamada a punto de originar.");
                }
            } else {
            	$this->_log->output('WARN: '.__METHOD__.': no se encontró '.
                    'llamada agendada esperada para agente: '.$sAgente);
            }
        }
        
        return $listaLlamadas;
    }
    
    /**
     * Procedimiento para obtener el número de segundos de reserva de una campaña
     */
    private function _getSegundosReserva($idCampaign)
    {
        return 30;  // TODO: volver configurable en DB o por campaña
    }

    /**
     * Función para listar todos los agentes que tengan al menos una llamada 
     * agendada, ahora, o en los siguientes RESERVA segundos, donde RESERVA se 
     * reporta por getSegundosReserva().
     *
     * @return array    Lista de agentes
     */
    private function _listarAgentesAgendadosReserva($id_campania)
    {
        $listaAgentes = array();
        $iSegReserva = $this->_getSegundosReserva($id_campania);
        $sFechaSys = date('Y-m-d');
        $iTimestamp = time();
        $sHoraInicio = date('H:i:s', $iTimestamp);
        $sHoraFinal = date('H:i:s', $iTimestamp + $iSegReserva);

        // Listar todos los agentes que tienen alguna llamada agendada dentro del horario
        $sPeticionAgentesAgendados = <<<PETICION_AGENTES_AGENDADOS
SELECT DISTINCT agent FROM calls, campaign
WHERE calls.id_campaign = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ? AND calls.time_init <= ? AND calls.time_end >= ?
    AND calls.retries < campaign.retries
    AND calls.id_campaign = campaign.id
    AND calls.agent IS NOT NULL
PETICION_AGENTES_AGENDADOS;
        $recordset = $this->_db->prepare($sPeticionAgentesAgendados);
        $recordset->execute(array(
            $id_campania,
            $sFechaSys,
            $sFechaSys,
            $sHoraFinal,
            $sHoraInicio));
        $listaAgentes = $recordset->fetchAll(PDO::FETCH_COLUMN, 0);
        return $listaAgentes;
    }

    /**
     * Función para contar todas las llamadas agendadas para el agente indicado,
     * clasificadas en llamadas agendables AHORA, y llamadas que caen en RESERVA.
     *
     * @return array Tupla de la forma array(AHORA => x, RESERVA => y)
     */
    private function _contarLlamadasAgendablesReserva($id_campania, $sAgent)
    {
        $cuentaLlamadas = array('AHORA' => 0, 'RESERVA' => 0);
        $iSegReserva = $this->_getSegundosReserva($id_campania);
        $sFechaSys = date('Y-m-d');
        $iTimestamp = time();
        $sHoraInicio = date('H:i:s', $iTimestamp);
        $sHoraFinal = date('H:i:s', $iTimestamp + $iSegReserva);
        
    $sPeticionLlamadasAgente = <<<PETICION_LLAMADAS_AGENTE
SELECT COUNT(*) AS TOTAL, SUM(IF(calls.time_init > ?, 1, 0)) AS RESERVA 
FROM calls, campaign
WHERE calls.id_campaign = ?
    AND calls.agent = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ? AND calls.time_init <= ? AND calls.time_end >= ?
    AND calls.retries < campaign.retries
    AND calls.id_campaign = campaign.id
PETICION_LLAMADAS_AGENTE;
        $recordset = $this->_db->prepare($sPeticionLlamadasAgente);
        $recordset->execute(array(
                $sHoraInicio,
                $id_campania,
                $sAgent,
                $sFechaSys,
                $sFechaSys,
                $sHoraFinal,
                $sHoraInicio));
        $tupla = $recordset->fetch(PDO::FETCH_NUM);
        $recordset->closeCursor();
        $cuentaLlamadas['RESERVA'] = $tupla[1];
        $cuentaLlamadas['AHORA'] = $tupla[0] - $cuentaLlamadas['RESERVA'];
        return $cuentaLlamadas;
    }

    /**
     * Procedimiento para listar la primera llamada agendable para la campaña y el
     * agente indicados. 
     */
    private function _listarLlamadasAgendables($id_campania, $sAgente)
    {
        $sFechaSys = date('Y-m-d');
        $sHoraSys = date('H:i:s');

        $sPeticionLlamadasAgente = <<<PETICION_LLAMADAS_AGENTE
SELECT calls.id_campaign, calls.id, calls.phone, calls.agent, calls.retries  
FROM calls, campaign
WHERE calls.id_campaign = ?
    AND calls.agent = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ? AND calls.time_init <= ? AND calls.time_end >= ?
    AND calls.retries < campaign.retries
    AND calls.id_campaign = campaign.id
ORDER BY calls.retries, calls.date_end, calls.time_end, calls.date_init, calls.time_init
LIMIT 0,1
PETICION_LLAMADAS_AGENTE;
        $recordset = $this->_db->prepare($sPeticionLlamadasAgente);
        $recordset->execute(array(
            $id_campania,
            $sAgente,
            $sFechaSys,
            $sFechaSys,
            $sHoraSys,
            $sHoraSys));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        return $tupla;
    }

    /**
     * Procedimiento que construye una plantilla de marcado a partir de una 
     * definición de trunk. Una plantilla de marcado es una cadena de texto de
     * la forma 'blablabla$OUTNUM$blabla' donde $OUTNUM$ es el lugar en que
     * debe constar el número saliente que va a marcarse. Por ejemplo, para
     * trunks de canales ZAP, la plantilla debe ser algo como Zap/g0/$OUTNUM$
     * 
     * @param   string  $sTrunk     Patrón que define el trunk a usar por la campaña
     * 
     * @return  mixed   La cadena de plantilla de marcado, o NULL en error 
     */
    private function _construirPlantillaMarcado($sTrunk)
    {
        if (is_null($sTrunk)) {
            // La campaña requiere marcar por plan de marcado FreePBX
            return array('TRUNK' => 'Local/$OUTNUM$@from-internal');
        } elseif (stripos($sTrunk, '$OUTNUM$') !== FALSE) {
            // Este es un trunk personalizado que provee $OUTNUM$ ya preparado
            return array('TRUNK' => $sTrunk);
        } elseif (strpos($sTrunk, 'SIP/') === 0        
            || stripos($sTrunk, 'Zap/') === 0
            || stripos($sTrunk, 'DAHDI/') === 0
            || strpos($sTrunk,  'IAX/') === 0
            || strpos($sTrunk, 'IAX2/') === 0) {
            // Este es un trunk Zap o SIP. Se debe concatenar el prefijo de marcado 
            // (si existe), y a continuación el número a marcar.
            $infoTrunk = $this->_leerPropiedadesTrunk($sTrunk);
            if (is_null($infoTrunk)) return NULL;
            
            // SIP/TRUNKLABEL/<PREFIX>$OUTNUM$
            $sPlantilla = $sTrunk.'/';
            if (isset($infoTrunk['PREFIX'])) $sPlantilla .= $infoTrunk['PREFIX'];
            $sPlantilla .= '$OUTNUM$';

            // Agregar información de Caller ID, si está disponible
            $plantilla = array('TRUNK' => $sPlantilla);
            if (isset($infoTrunk['CID']) && trim($infoTrunk['CID']) != '')
                $plantilla['CID'] = $infoTrunk['CID'];
            return $plantilla;
        } else {
            $this->_log->output("ERR: trunk '$sTrunk' es un tipo de trunk desconocido. Actualice su versión de CallCenter.");
            return NULL;
        }
    }

    /**
     * Procedimiento que lee las propiedades del trunk indicado a partir de la
     * base de datos de FreePBX. Este procedimiento puede tomar algo de tiempo,
     * porque se requiere la información de /etc/amportal.conf para obtener las
     * credenciales para conectarse a la base de datos.
     * 
     * @param   string  $sTrunk     Trunk sobre la cual leer información de DB
     * 
     * @return  mixed   NULL en caso de error, o arreglo de propiedades
     */
    private function _leerPropiedadesTrunk($sTrunk)
    {
        /* Para evitar excesivas conexiones, se mantiene un cache de la información leída
         * acerca de un trunk durante los últimos 30 segundos. 
         */
        if (isset($this->_plantillasMarcado[$sTrunk])) {
            if (time() - $this->_plantillasMarcado[$sTrunk]['TIMESTAMP'] >= 30)
                unset($this->_plantillasMarcado[$sTrunk]);
        }
        if (isset($this->_plantillasMarcado[$sTrunk])) {
            return $this->_plantillasMarcado[$sTrunk]['PROPIEDADES'];
        }
        
        $dbConn = $this->_abrirConexionFreePBX();
        if (is_null($dbConn)) return NULL;

        $infoTrunk = NULL;
        $sTrunkConsulta = $sTrunk;
        
        try {
            if ($this->_existeTrunksFPBX) {
                /* Consulta directa de las opciones del trunk indicado. Se debe 
                 * separar la tecnología del nombre de la troncal, y consultar en
                 * campos separados en la tabla asterisk.trunks */
                $camposTrunk = explode('/', $sTrunkConsulta, 2);
                if (count($camposTrunk) < 2) {
                    $this->_log->output("ERR: trunk '$sTrunkConsulta' no se puede interpretar, se espera formato TECH/CHANNELID");
                    $dbConn = NULL;
                    return NULL;
                }
                
                // Formas posibles de localizar la información deseada de troncales
                $listaIntentos = array(
                    array(
                        'tech'      => strtolower($camposTrunk[0]),
                        'channelid' => $camposTrunk[1]
                    ),
                );
                if ($listaIntentos[0]['tech'] == 'dahdi') {
                    $listaIntentos[] = array(
                        'tech'      => 'zap',
                        'channelid' => $camposTrunk[1]
                    );
                }
                $sPeticionSQL = 
                    'SELECT outcid AS CID, dialoutprefix AS PREFIX '.
                    'FROM trunks WHERE tech = ? AND channelid = ?';
                $recordset = $dbConn->prepare($sPeticionSQL);
                foreach ($listaIntentos as $tuplaIntento) {
                    $recordset->execute(array($tuplaIntento['tech'], $tuplaIntento['channelid']));
                    $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
                    $recordset->closeCursor();
                    if ($tupla) {
                        $infoTrunk = array();
                        if ($tupla['CID'] != '') $infoTrunk['CID'] = $tupla['CID'];
                        if ($tupla['PREFIX'] != '') $infoTrunk['PREFIX'] = $tupla['PREFIX'];
                        $this->_plantillasMarcado[$sTrunk] = array(
                            'TIMESTAMP'     =>  time(),
                            'PROPIEDADES'   =>  $infoTrunk,
                        );
                        break;
                    }
                }
            } else {
                /* Buscar cuál de las opciones describe el trunk indicado. En FreePBX,
                 * la información de los trunks está guardada en la tabla 'globals',
                 * donde globals.value tiene el nombre del trunk buscado, y 
                 * globals.variable es de la forma OUT_NNNNN. El valor de NNN se usa
                 * para consultar el resto de las variables 
                 */
                $recordset = $dbConn->prepare("SELECT variable FROM globals WHERE value = ? AND variable LIKE 'OUT_%'");
                $recordset->execute(array($sTrunkConsulta));
                $sVariable = $recordset->fetch(PDO::FETCH_COLUMN, 0);
                $recordset->closeCursor();
                if (!$sVariable && strpos($sTrunkConsulta, 'DAHDI') !== 0) {
                    $this->_log->output("ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX (1) - trunk no se encuentra!");
                    $dbConn = NULL;
                    return NULL;
                }
                
                if (!$sVariable && strpos($sTrunkConsulta, 'DAHDI') === 0) {
                    /* Podría ocurrir que esta versión de FreePBX todavía guarda la 
                     * información sobre troncales DAHDI bajo nombres ZAP. Para 
                     * encontrarla, se requiere de transformación antes de la consulta. 
                     */
                    $sTrunkConsulta = str_replace('DAHDI', 'ZAP', $sTrunk);
                    $recordset->execute(array($sTrunkConsulta));
                    $sVariable = $recordset->fetch(PDO::FETCH_COLUMN, 0);
                    $recordset->closeCursor();
                    if (!$sVariable) {
                        $this->_log->output("ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX (1) - trunk no se encuentra!");
                        $dbConn = NULL;
                        return NULL;
                    }
                }
                
                $regs = NULL;        
                if (!preg_match('/^OUT_([[:digit:]]+)$/', $sVariable, $regs)) {
                    $this->_log->output("ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX (1) - se esperaba OUT_NNN pero se encuentra $sVariable - versión incompatible de FreePBX?");
                } else {
                    $iNumTrunk = $regs[1];
                    
                    // Consultar todas las variables asociadas al trunk
                    $sPeticionSQL = 'SELECT variable, value FROM globals WHERE variable LIKE ?';
                    $recordset = $dbConn->prepare($sPeticionSQL);
                    $recordset->execute(array('OUT%_'.$iNumTrunk));
                    $recordset->setFetchMode(PDO::FETCH_ASSOC);
                    $infoTrunk = array();
                    $sRegExp = '/^OUT(.+)_'.$iNumTrunk.'$/';
                    foreach ($recordset as $tupla) {
                        $regs = NULL;
                        if (preg_match($sRegExp, $tupla['variable'], $regs)) {
                            $sValor = trim($tupla['value']);
                            if ($sValor != '') $infoTrunk[$regs[1]] = $sValor;
                        }
                    }
                    $this->_plantillasMarcado[$sTrunk] = array(
                        'TIMESTAMP'     =>  time(),
                        'PROPIEDADES'   =>  $infoTrunk,
                    );
                }
            }
        } catch (PDOException $e) {
        	$this->_log->output(
                "ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX - ".
                implode(' - ', $e->errorInfo));
        }

        $dbConn = NULL;
        return $infoTrunk;
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

    private function _leerTiempoContestar($idCampaign)
    {
    	return $this->_tuberia->AMIEventProcess_leerTiempoContestar($idCampaign);
    }

    /* Contar el número de llamadas que se colocaron en la cola $queue y que han
     * sido originadas, pero todavía esperan respuesta */
    private function _contarLlamadasEsperandoRespuesta($queue)
    {
        return $this->_tuberia->AMIEventProcess_contarLlamadasEsperandoRespuesta($queue);
    }

    // Construir la cadena de variables, con separador adecuado según versión Asterisk
    private function _construirCadenaVariables($listaVar)
    {
        // "ID_CAMPAIGN={$infoCampania->id}|ID_CALL={$tupla->id}|NUMBER={$tupla->phone}|QUEUE={$infoCampania->queue}|CONTEXT={$infoCampania->context}",
/*
        $versionMinima = array(1, 6, 0);
        while (count($versionMinima) < count($this->_asteriskVersion))
            array_push($versionMinima, 0);
        while (count($versionMinima) > count($this->_asteriskVersion))
            array_push($this->_asteriskVersion, 0);
        $sSeparador = ($this->_asteriskVersion >= $versionMinima) ? ',' : '|';
        $sCadenaVar = '';
        foreach ($listaVar as $sKey => $sVal) {
            if ($sCadenaVar != '') $sCadenaVar .= $sSeparador;
            $sCadenaVar .= "{$sKey}={$sVal}";
        }
        return $sCadenaVar;
*/
        $lista = array();
        foreach ($listaVar as $sKey => $sVal) {
            $lista[] = "{$sKey}={$sVal}";
        }
        return $this->_construirListaParametros($lista);
    }
    
    private function _construirListaParametros($listaVar)
    {
        $versionMinima = array(1, 6, 0);
        while (count($versionMinima) < count($this->_asteriskVersion))
            array_push($versionMinima, 0);
        while (count($versionMinima) > count($this->_asteriskVersion))
            array_push($this->_asteriskVersion, 0);
        $sSeparador = ($this->_asteriskVersion >= $versionMinima) ? ',' : '|';
        return implode($sSeparador, $listaVar);
    }

    /**************************************************************************/

    public function msg_requerir_nuevaListaAgentes($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
    	$this->_log->output("INFO: $sFuente requiere refresco de lista de agentes");
        $this->_actualizarInformacionRemota_agentes();
    }
    
    public function msg_actualizarCanalRemoto($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_actualizarCanalRemoto'), $datos);
    }

    public function msg_sqlinsertcalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_sqlinsertcalls'), $datos);
    }

    public function msg_sqlupdatecalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_sqlupdatecalls'), $datos);
    }
    
    public function msg_sqlupdatecurrentcalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_sqlupdatecurrentcalls'), $datos);
    }    

    public function msg_sqlinsertcurrentcalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_sqlinsertcurrentcalls'), $datos);
    }
    
    public function msg_sqldeletecurrentcalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_sqldeletecurrentcalls'), $datos);
    }
    
    public function msg_sqlupdatestatcampaign($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_sqlupdatestatcampaign'), $datos);
    }
    
    public function msg_verificarFinLlamadasAgendables($sFuente, $sDestino, 
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_verificarFinLlamadasAgendables'), $datos);
    }

    public function msg_finalizando($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
    	$this->_log->output('INFO: recibido mensaje de finalización, se detienen campañas...');
        $this->_finalizandoPrograma = TRUE;
    }
    
    public function msg_finalsql($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if (!$this->_finalizandoPrograma) {
            $this->_log->output('WARN: AMIEventProcess envió mensaje antes que HubProcess');
        }
        $this->_finalizandoPrograma = TRUE;
        $this->_tuberia->msg_HubProcess_finalizacionTerminada();
    }
    
    /**************************************************************************/

    private function _sqlinsertcalls($paramInsertar)
    {
        // Porción que identifica la tabla a modificar
        $tipo_llamada = $paramInsertar['tipo_llamada'];
        unset($paramInsertar['tipo_llamada']);
        if ($tipo_llamada == 'outgoing') {
            $sqlTabla = 'INSERT INTO calls ';
        } else {
            $sqlTabla = 'INSERT INTO call_entry ';
        }

        // Caso especial: llamada entrante requiere ID de contacto
        if ($tipo_llamada == 'incoming') {
            /* Se consulta el posible contacto en base al caller-id. Si hay 
             * exactamente un contacto, su ID se usa para la inserción. */
            $recordset = $this->_db->prepare('SELECT id FROM contact WHERE telefono = ?');
            $recordset->execute(array($paramInsertar['callerid']));
            $listaIdContactos = $recordset->fetchAll(PDO::FETCH_COLUMN, 0);
            if (count($listaIdContactos) == 1) {
                $paramInsertar['id_contact'] = $listaIdContactos[0];
            }
        }

        $sqlCampos = array();
        $params = array();
        foreach ($paramInsertar as $k => $v) {
            $sqlCampos[] = $k;
            $params[] = $v;
        }
        $sql = $sqlTabla.'('.implode(', ', $sqlCampos).') VALUES ('.
            implode(', ', array_fill(0, count($params), '?')).')';
        
        $sth = $this->_db->prepare($sql);
        $sth->execute($params);
        $idCall = $this->_db->lastInsertId();
        
        // Para llamada entrante se debe de insertar el log de progreso
        if ($tipo_llamada == 'incoming') {
            $sth = $this->_db->prepare(
                'INSERT INTO call_progress_log (datetime_entry, id_call_incoming, new_status, uniqueid, trunk) '.
                'VALUES (?, ?, ?, ?, ?)');
            $sth->execute(array($paramInsertar['datetime_entry_queue'], $idCall,
                'OnQueue', $paramInsertar['uniqueid'], $paramInsertar['trunk']));
        }
        
        // Mandar de vuelta el ID de inserción a AMIEventProcess
        $this->_tuberia->msg_AMIEventProcess_idnewcall(
            $tipo_llamada, $paramInsertar['uniqueid'], $idCall);
    }

    // Procedimiento que actualiza una sola llamada de la tabla calls o call_entry
    private function _sqlupdatecalls($paramActualizar)
    {
    	// Porción que identifica la tabla a modificar
        if ($paramActualizar['tipo_llamada'] == 'outgoing') {
    		$sqlTabla = 'UPDATE calls SET ';
    	} else {
    		$sqlTabla = 'UPDATE call_entry SET ';
    	}
        unset($paramActualizar['tipo_llamada']);

        // Porción que identifica la tupla a modificar
        $sqlWhere = array();
        $paramWhere = array();
        if (isset($paramActualizar['id_campaign'])) {
        	if (!is_null($paramActualizar['id_campaign'])) {
                $sqlWhere[] = 'id_campaign = ?';
                $paramWhere[] = $paramActualizar['id_campaign'];
            }
            unset($paramActualizar['id_campaign']);
        }
        if (isset($paramActualizar['id'])) {
            $sqlWhere[] = 'id = ?';
            $paramWhere[] = $paramActualizar['id'];
            unset($paramActualizar['id']);
        }
        
        // Parámetros a modificar
        $sqlCampos = array();
        $paramCampos = array();
        
        // Caso especial: retries se debe de incrementar
        if (isset($paramActualizar['inc_retries'])) {
            $sqlCampos[] = 'retries = retries + ?';
            $paramCampos[] = $paramActualizar['inc_retries'];
        	unset($paramActualizar['inc_retries']);
        }
        foreach ($paramActualizar as $k => $v) {
        	$sqlCampos[] = "$k = ?";
            $paramCampos[] = $v;
        }
        
        $sql = $sqlTabla.implode(', ', $sqlCampos).' WHERE '.implode(' AND ', $sqlWhere);
        $params = array_merge($paramCampos, $paramWhere);
        
        $sth = $this->_db->prepare($sql);
        $sth->execute($params);
    }
    
    // Procedimiento que inserta un solo registro en current_calls o current_call_entry
    private function _sqlinsertcurrentcalls($paramInsertar)
    {
        // Porción que identifica la tabla a modificar
        $tipo_llamada = $paramInsertar['tipo_llamada'];
        unset($paramInsertar['tipo_llamada']);
        if ($tipo_llamada == 'outgoing') {
            $sqlTabla = 'INSERT INTO current_calls ';
        } else {
            $sqlTabla = 'INSERT INTO current_call_entry ';
        }

    	$sqlCampos = array();
        $params = array();
        foreach ($paramInsertar as $k => $v) {
        	$sqlCampos[] = $k;
            $params[] = $v;
        }
        $sql = $sqlTabla.'('.implode(', ', $sqlCampos).') VALUES ('.
            implode(', ', array_fill(0, count($params), '?')).')';
        
        $sth = $this->_db->prepare($sql);
        $sth->execute($params);
        
        // Mandar de vuelta el ID de inserción a AMIEventProcess
        $this->_tuberia->msg_AMIEventProcess_idcurrentcall(
            $tipo_llamada, 
            isset($paramInsertar['id_call_entry']) 
                ? $paramInsertar['id_call_entry'] 
                : $paramInsertar['id_call'], 
            $this->_db->lastInsertId());
    }
    
    // Procedimiento que actualiza un solo registro en current_calls o current_call_entry
    private function _sqlupdatecurrentcalls($paramActualizar)
    {
        // Porción que identifica la tabla a modificar
        if ($paramActualizar['tipo_llamada'] == 'outgoing') {
            $sqlTabla = 'UPDATE current_calls SET ';
        } else {
            $sqlTabla = 'UPDATE current_call_entry SET ';
        }
        unset($paramActualizar['tipo_llamada']);

        // Porción que identifica la tupla a modificar
        $sqlWhere = array();
        $paramWhere = array();
        if (isset($paramActualizar['id'])) {
            $sqlWhere[] = 'id = ?';
            $paramWhere[] = $paramActualizar['id'];
            unset($paramActualizar['id']);
        }
        
        // Parámetros a modificar
        $sqlCampos = array();
        $paramCampos = array();
        
        foreach ($paramActualizar as $k => $v) {
            $sqlCampos[] = "$k = ?";
            $paramCampos[] = $v;
        }
        
        $sql = $sqlTabla.implode(', ', $sqlCampos).' WHERE '.implode(' AND ', $sqlWhere);
        $params = array_merge($paramCampos, $paramWhere);
        
        $sth = $this->_db->prepare($sql);
        $sth->execute($params);
    }

    private function _sqldeletecurrentcalls($paramBorrar)
    {
        // Porción que identifica la tabla a modificar
        $sth = $this->_db->prepare(($paramBorrar['tipo_llamada'] == 'outgoing') 
            ? 'DELETE FROM current_calls WHERE id = ?'
            : 'DELETE FROM current_call_entry WHERE id = ?');
        $sth->execute(array($paramBorrar['id']));
    }
    
    private function _sqlupdatestatcampaign($id_campaign, $num_completadas, 
        $promedio, $desviacion)
    {
    	$sth = $this->_db->prepare(
            'UPDATE campaign SET num_completadas = ?, promedio = ?, desviacion = ? WHERE id = ?');
        $sth->execute(array($num_completadas, $promedio, $desviacion, $id_campaign));
    }
    
    private function _actualizarCanalRemoto($sAgentNum, $tipo_llamada, $uniqueid)
    {
    	if (is_null($this->_ami)) return;
        $sCanalRemoto = NULL;
        $r = $this->_ami->Command('agent show online');
        if (is_array($r) && isset($r['data'])) {
            $lineasRespuesta = explode("\n", $r['data']);
            foreach ($lineasRespuesta as $sLinea) {
                if (preg_match('/^(\d{2,})\s+\(/', $sLinea, $regs)) {
                    if ($regs[1] == $sAgentNum) {
                        if (preg_match('|talking to (\w+/\S{2,})|', $sLinea, $regs)) {
                            $sCanalRemoto = $regs[1];
                            break;
                        }
                    }
                }
            }
            if (!is_null($sCanalRemoto)) {
                if (strpos($sCanalRemoto, 'Local/') === 0) {
                	$this->_log->output('WARN: '.__METHOD__.": el agente ".
                        "Agent/$sAgentNum está hablando con canal $sCanalRemoto ".
                        "según 'agent show online'");
                }
            	$this->_tuberia->msg_AMIEventProcess_canalRemotoAgente(
                    $sAgentNum, $tipo_llamada, $uniqueid, $sCanalRemoto);
            }
        }
    }
    
    private function _verificarFinLlamadasAgendables($sAgente, $id_campania, $infoSeguimiento)
    {
    	if (is_null($this->_ami)) return;
        $l = $this->_contarLlamadasAgendablesReserva($id_campania, $sAgente);
        if ($l['AHORA'] == 0 && $l['RESERVA'] == 0) {
        	/* Por ahora el agente ya no tiene llamadas agendables y se debe
             * reducir la cuenta de pausas del agente. Si la cuenta es 1, 
             * entonces se debe quitar la pausa real. */
            if ($this->DEBUG) {
            	$this->_log->output('DEBUG: '.__METHOD__.': el siguiente agente '.
                    'no tiene más llamadas agendadas: '.$sAgente);
            }
            if ($infoSeguimiento['num_pausas'] == 1 && !is_null($this->_ami)) {
            	$r = $this->_ami->QueuePause(NULL, $sAgente, 'false');
            }
            $this->_tuberia->msg_AMIEventProcess_quitarReservaAgente($sAgente);
        }
    }
}
?>