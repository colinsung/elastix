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
  $Id: Llamada.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

class Llamada
{
    // Relaciones con otros objetos conocidos
    private $_log;
    private $_tuberia;

    // Referencia a contenedor de llamadas e índice dentro del contenedor
    private $_listaLlamadas;

    // Agente que está atendiendo la llamada, o NULL para llamada sin atender
    var $agente = NULL;		 

    // Campaña a la que pertenece la llamada, o NULL para llamada entrante sin campaña
    var $campania = NULL;


    // Propiedades específicas de la llamada

    // Tipo de llamada, 'incoming', 'outgoing'
    private $_tipo_llamada;
    
    /* ID en la base de datos de la llamada, o NULL para llamada entrante sin 
     * registrar. Esta propiedad es una de las propiedades indexables en 
     * ListaLlamadas, junto con _tipo_llamada */
    private $_id_llamada = NULL;
    
    /* Cadena de marcado que se ha usado para la llamada saliente. Esta 
     * propiedad es una de las propiedades indexables en ListaLlamadas. */
    private $_dialstring = NULL;
    
    /* Valor de Uniqueid proporcionado por Asterisk para la llamada. Esta 
     * propiedad es una de las propiedades indexables en ListaLlamadas. */
    private $_uniqueid = NULL;
    
    /* Canal indicado por OriginateResponse o Join que puede usarse para 
     * redirigir la llamada. Se usa para redirigir la llamada en caso de llamada
     * agendada, y puede que también sirva para manipular en caso de hold y 
     * transferencia. Esta propiedad es una de las propiedades indexables en 
     * ListaLlamadas. */
    private $_channel = NULL;
    private $_actualchannel = NULL;

    /* Cadena usada en Originate para el valor de ActionID, para identificar 
     * esta llamada al recibir el OriginateResponse, en el caso de troncal 
     * específica (sin usar plan de marcado). Esta propiedad es una de las
     * propiedades indexables en ListaLlamadas. */
    private $_actionid = NULL;

    /* Estimación de troncal de la llamada, obtenida a partir de Channel de 
     * OriginateResponse o Join. Se usa para llamadas entrantes. */
    private $_trunk = NULL;

    /* Estado de la llamada. Para llamadas salientes, el estado puede ser:
     * NULL     Estado inicial, llamada recién ha sido avisada 
     * Placing  Se ha iniciado Originate para esta llamada. En este estado debe
     *          de tenerse un valor para timestamp_originate_start, que se 
     *          supone fue escrito por CampaignProcess.
     * Ringing  Se ha recibido OriginateResponse para esta llamada. En este 
     *          estado debe de tenerse un valor para timestamp_originate_end. 
     *          Si no se ha recibido ya Link para la llamada, se escribe Ringing
     *          en la base de datos. 
     * OnQueue  Se ha recibido Join para esta llamada. En este estado debe de
     *          tenerse un valor para timestamp_enterqueue. Si no se ha recibido
     *          ya Link para la llamada, se escribe OnQueue en la base de datos.
     * Success  Se ha recibido Link para esta llamada. En este estado debe de
     *          tenerse un valor para timestamp_start. La propiedad duration_wait
     *          se calcula entre timestamp_start y timestamp_enterqueue. Se
     *          escribe Success en la base de datos.
     * OnHold   La llamada ha sido enviada a hold. Se escribe OnHold en la base
     *          de datos.
     * Hangup   La llamada ha sido colgada. En este estado debe de tenerse un
     *          valor para timestamp_end. La propiedad duration_call se calcula
     *          entre timestamp_end y timestamp_start
     * Failure  No se puede colocar la llamada. Este estado puede ocurrir por:
     *          - Llamada falla de inmediato el Originate
     *          - Llamada ha pasado demasiado tiempo en estado Placing
     *          - Se ha recibido OriginateResponse fallido
     *          - Se ha recibido OriginateResponse exitoso, pero se había recibido 
     *            previamente un Hangup sobre la misma llamada.
     * ShortCall La llamada ha sido correctamente conectada en Link, pero luego
     *          se cuelga en un tiempo menor al indicado en la configuración
     *          de llamada corta.
     * NoAnswer La llamada fue colgada sin ser conectada antes de entrar a la cola
     * Abandoned La llamada fue colgada sin ser conectada luego de entrar a la cola
     * 
     * Puede ocurrir que se reciban los eventos Join y Link antes que 
     * OriginateResponse. Entonces se siguen las reglas detalladas arriba para
     * la escritura del estado. Los timestamps siempre se escriben al llegar
     * el respectivo mensaje.
     * 
     * Para llamadas entrantes, los estados válidos son: 
     * OnQueue  Se escribe 'en-cola' en la base de datos
     * Success  Se escribe 'activa' en la base de datos
     * OnHold   Se escribe 'hold' en la base de datos
     * Hangup   Se escribe 'terminada' si la llamada recibió Link, o 'abandonada'
     *          si no lo hizo.
     */
    private $_status = NULL;
    
    var $phone;     // Número marcado para llamada saliente o Caller-ID para llamada entrante
    var $id_current_call;   // ID del registro correspondiente en current_call[_entry]
    var $request_hold = FALSE;  // Se asigna a VERDADERO al invocar requerimiento hold, y se verifica en Unlink 
    
    // Timestamps correspondientes a diversos eventos de la llamada
    var $timestamp_originatestart = NULL;   // Inicio de Originate en CampaignProcess
    var $timestamp_originateend = NULL;     // Recepción de OriginateResponse
    var $timestamp_enterqueue = NULL;       // Recepción de Join
    var $timestamp_link = NULL;             // Recepción de primer Link
    var $timestamp_hangup = NULL;           // Recepción de Hangup
    
    // Lista de canales auxiliares asociados a la llamada.
    var $AuxChannels = array();

    // ID de la cola de campaña entrante. Sólo para llamadas entrantes
    var $id_queue_call_entry = NULL;
    
    private $_queuenumber = NULL;

    // Referencia al agente agendado
    var $agente_agendado = NULL;

    // Actualizaciones pendientes en la base de datos por faltar id_llamada
    private $_actualizacionesPendientes = array();
    
    // Este constructor sólo debe invocarse desde ListaLlamadas::nuevaLlamada()
    function __construct(ListaLlamadas $lista, $tipo_llamada, $tuberia, $log)
    {
    	$this->_listaLlamadas = $lista;
        $this->_tipo_llamada = $tipo_llamada;
        $this->_tuberia = $tuberia;
        $this->_log = $log;
    }
    
    public function __get($s)
    {
        switch ($s) {
        case 'tipo_llamada':    return $this->_tipo_llamada;
        case 'id_llamada':      return $this->_id_llamada;
        case 'dialstring':      return $this->_dialstring;
        case 'Uniqueid':
        case 'uniqueid':        return $this->_uniqueid;
        case 'channel':         return $this->_channel;
        case 'actualchannel':   return $this->_actualchannel;
        case 'trunk':           return $this->_trunk;
        case 'status':          return $this->_status;
        case 'actionid':        return $this->_actionid;
        case 'duration':        return (!is_null($this->timestamp_link) && !is_null($this->timestamp_hangup)) 
                                        ? $this->timestamp_hangup - $this->timestamp_link : NULL;
        case 'duration_wait':   return (!is_null($this->timestamp_link) && !is_null($this->timestamp_enterqueue)) 
                                        ? $this->timestamp_link - $this->timestamp_enterqueue : NULL;
        case 'duration_answer': return (!is_null($this->timestamp_link) && !is_null($this->timestamp_originatestart)) 
                                        ? $this->timestamp_link - $this->timestamp_originatestart : NULL;
        case 'esperando_contestar':
                                return (!is_null($this->timestamp_originatestart) && is_null($this->timestamp_originateend));
        default:
            $this->_log->output('ERR: '.__METHOD__.' - propiedad no implementada: '.$s);
            die(__METHOD__.' - propiedad no implementada: '.$s."\n");
        }
    }
    
    public function __set($s, $v)
    {
        switch ($s) {
        case 'tipo_llamada':
            if (in_array($v, array('incoming', 'outgoing')))
                $this->_tipo_llamada = (string)$v;
            break;
        case 'status':
            if (in_array($v, array('Placing', 'Ringing', 'OnQueue', 'Success', 
                'OnHold', 'Hangup', 'Failure', 'ShortCall', 'NoAnswer', 
                'Abandoned')))
                $this->_status = (string)$v;
            break;
        case 'id_llamada':
            $v = (int)$v;
            if (is_null($this->_id_llamada) || $this->_id_llamada != $v) {
            	$sIndice = ($this->_tipo_llamada == 'incoming') ? 'id_llamada_entrante' : 'id_llamada_saliente';
                if (!is_null($this->_id_llamada))
                    $this->_listaLlamadas->removerIndice($sIndice, $this->_id_llamada);
                $this->_id_llamada = $v;
                $this->_listaLlamadas->agregarIndice($sIndice, $this->_id_llamada, $this);
                
                // Si la llamada era entrante, entonces puede que hayan actualizaciones pendientes
                if (count($this->_actualizacionesPendientes) > 0) {
                    if (isset($this->_actualizacionesPendientes['sqlupdatecalls'])) {
                        $this->_log->output('INFO: '.__METHOD__.': ya se tiene ID de llamada, actualizando call_entry...');
                    	$paramActualizar = $this->_actualizacionesPendientes['sqlupdatecalls'];
                        unset($this->_actualizacionesPendientes['sqlupdatecalls']);
                        
                        $paramActualizar['id'] = $this->id_llamada;
                        $this->_tuberia->msg_CampaignProcess_sqlupdatecalls($paramActualizar);

                        // Lanzar evento ECCP en ECCPProcess
                        $this->_tuberia->msg_ECCPProcess_AgentLinked($this->tipo_llamada, 
                            is_null($this->campania) ? NULL : $this->campania->id,
                            $this->id_llamada, $this->agente->channel, 
                            is_null($this->actualchannel) ? $this->channel : $this->actualchannel,
                            date('Y-m-d H:i:s', $this->timestamp_link));
                    }
                    if (isset($this->_actualizacionesPendientes['sqlinsertcurrentcalls'])) {
                        $this->_log->output('INFO: '.__METHOD__.': ya se tiene ID de llamada, insertando current_call_entry...');
                        $paramInsertarCC = $this->_actualizacionesPendientes['sqlinsertcurrentcalls'];
                        unset($this->_actualizacionesPendientes['sqlinsertcurrentcalls']);

                        $paramInsertarCC[($this->tipo_llamada == 'incoming') ? 'id_call_entry' : 'id_call'] = 
                            $this->id_llamada;
                        $this->_tuberia->msg_CampaignProcess_sqlinsertcurrentcalls($paramInsertarCC);
                    }
                    if (count($this->_actualizacionesPendientes) > 0) {
                    	$this->_log->output('ERR: '.__METHOD__.': actualización pendiente no implementada');
                    }
                }
            }
            break;
        case 'dialstring':
            $v = (string)$v;
            if (is_null($this->_dialstring) || $this->_dialstring != $v) {
                if (!is_null($this->_dialstring))
                    $this->_listaLlamadas->removerIndice('dialstring', $this->_dialstring);
                $this->_dialstring = $v;
                $this->_listaLlamadas->agregarIndice('dialstring', $this->_dialstring, $this);
            }
            break;
        case 'actionid':
            $v = (string)$v;
            if (is_null($this->_actionid) || $this->_actionid != $v) {
                if (!is_null($this->_actionid))
                    $this->_listaLlamadas->removerIndice('actionid', $this->_actionid);
                $this->_actionid = $v;
                $this->_listaLlamadas->agregarIndice('actionid', $this->_actionid, $this);
            }
            break;
        case 'channel':
            $v = (string)$v;
            if (is_null($this->_channel) || $this->_channel != $v) {
                if (!is_null($this->_channel))
                    $this->_listaLlamadas->removerIndice('channel', $this->_channel);
                $this->_channel = $v;
                $this->_listaLlamadas->agregarIndice('channel', $this->_channel, $this);
                
                // El valor de trunk es derivado de channel
                $this->_trunk = NULL;
                $regs = NULL;
                if (preg_match('/^(.+)-[0-9a-fA-F]+$/', $this->_channel, $regs)) {
                	$this->_trunk = $regs[1];
                    
                }
                
                // Si el canal de la llamada no es Local, es el actualchannel
                if (strpos($this->_channel, 'Local/') !== 0) {
                	$this->actualchannel = $v;
                }
            }
            break;
        case 'actualchannel':
            $v = (string)$v;
            if (is_null($this->_actualchannel) || $this->_actualchannel != $v) {
                if (!is_null($this->_actualchannel))
                    $this->_listaLlamadas->removerIndice('actualchannel', $this->_actualchannel);
                $this->_actualchannel = $v;
                $this->_listaLlamadas->agregarIndice('actualchannel', $this->_actualchannel, $this);
                
                // El valor de trunk es derivado de channel
                /*
                $this->_trunk = NULL;
                $regs = NULL;
                if (preg_match('/^(.+)-[0-9a-fA-F]+$/', $this->_channel, $regs)) {
                    $this->_trunk = $regs[1];
                }
                */
            }
            break;
        case 'uniqueid':
        case 'Uniqueid':
            $v = (string)$v;
            if (is_null($this->_uniqueid) || $this->_uniqueid != $v) {
                if (!is_null($this->_uniqueid))
                    $this->_listaLlamadas->removerIndice('uniqueid', $this->_uniqueid);
                $this->_uniqueid = $v;
                $this->_listaLlamadas->agregarIndice('uniqueid', $this->_uniqueid, $this);
                
                // Actualizar el Uniqueid en la base de datos
                if (!is_null($this->_id_llamada)) {
                	$paramActualizar = array(
                        'tipo_llamada'  =>  $this->tipo_llamada,
                        'id_campaign'   =>  is_null($this->campania) ? NULL : $this->campania->id,
                        'id'            =>  $this->_id_llamada,
                        'uniqueid'      =>  $this->_uniqueid,
                    );
                    $this->_tuberia->msg_CampaignProcess_sqlupdatecalls($paramActualizar);
                }
                if (!is_null($this->id_current_call)) {
                    $paramActualizar = array(
                        'tipo_llamada'  =>  $this->tipo_llamada,
                        'id'            =>  $this->id_current_call,
                        'uniqueid'      =>  $this->_uniqueid,
                    );
                    $this->_tuberia->msg_CampaignProcess_sqlupdatecurrentcalls($paramActualizar);
                }
            }
            break;
        default:
            $this->_log->output('ERR: '.__METHOD__.' - propiedad no implementada: '.$s);
            die(__METHOD__.' - propiedad no implementada: '.$s."\n");
        }
    }
    
    public function registerAuxChannels()
    {
    	foreach (array_keys($this->AuxChannels) as $k)
            $this->_listaLlamadas->agregarIndice('auxchannel', $k, $this);
    }
    
    public function unregisterAuxChannels()
    {
        foreach (array_keys($this->AuxChannels) as $k)
            $this->_listaLlamadas->removerIndice('auxchannel', $k);
    }
    
    public function resumenLlamada()
    {
    	$resumen = array(
            'calltype'              =>  $this->tipo_llamada,
            'campaign_id'           =>  is_null($this->campania) ? NULL : $this->campania->id,
            'dialnumber'            =>  $this->phone,
            'callid'                =>  $this->id_llamada,
            'currentcallid'         =>  $this->id_current_call,
            'datetime_enterqueue'   => date('Y-m-d H:i:s', $this->timestamp_enterqueue),
            'datetime_linkstart'    => date('Y-m-d H:i:s', $this->timestamp_link),
            'queuenumber'           =>  $this->_queuenumber,
        );
        if (is_null($resumen['queuenumber']) && !is_null($this->campania)) {
        	$resumen['queuenumber'] = $this->campania->queue;
        }
        if ($this->tipo_llamada == 'outgoing') {
        	$resumen['datetime_dialstart'] = date('Y-m-d H:i:s', $this->timestamp_originatestart);
            $resumen['datetime_dialstart'] = date('Y-m-d H:i:s', $this->timestamp_originateend);
        }        
        return $resumen;
    }
    
    public function llamadaFueOriginada($timestamp, $uniqueid, $channel, 
        $sStatus, $iCause = NULL, $sCauseTxt = NULL)
    {
        $this->timestamp_originateend = $timestamp;
        if (is_null($this->channel)) $this->channel = $channel;

        if ($uniqueid == '<null>') $uniqueid = NULL;
        if ($sStatus == 'Success') $sStatus = 'Ringing';
        if ($this->status == 'Placing' || $sStatus == 'Failure')
            $this->status = $sStatus;
        if (is_null($this->uniqueid) && !is_null($uniqueid))
            $this->uniqueid = $uniqueid;

        /*
        if ($this->DEBUG) {
            // Desactivado porque rellena el log
            $this->_log->output("DEBUG: llamada identificada es: {$this->actionid} : ".
                print_r($this, TRUE));
        }
        */

        // Preparar propiedades a actualizar en DB
        $paramActualizar = array(
            'tipo_llamada'  =>  $this->tipo_llamada,
            'id_campaign'   =>  $this->campania->id,
            'id'            =>  $this->id_llamada,
            
            'status'        =>  $this->status,
            'Uniqueid'      =>  $this->uniqueid,
            'fecha_llamada' =>  date('Y-m-d H:i:s', $this->timestamp_originateend),
            'inc_retries'   =>  ($sStatus == 'Failure') ? 1 : 0,
        );
        
        /* En caso de fallo de Originate, y si se tienen canales auxiliares, el
         * Hangup registrado en el canal auxiliar puede tener la causa del fallo
         */
        $iSegundosEspera = $this->timestamp_originateend - $this->timestamp_originatestart;
        if ($sStatus == 'Failure') {
            // Una causa de colgado de 0 no sirve.
            if (!is_null($iCause) && $iCause == 0) {
            	$iCause = NULL; $sCauseTxt = NULL;
            }
            
            if (is_null($iCause)) foreach ($this->AuxChannels as $eventosAuxiliares) {
                if (isset($eventosAuxiliares['Hangup']) && $eventosAuxiliares['Hangup']['Cause'] != 0) {
                    $iCause = $eventosAuxiliares['Hangup']['Cause'];
                    $sCauseTxt = $eventosAuxiliares['Hangup']['Cause-txt'];
                }
            }
            if (!is_null($iCause)) {
                $paramActualizar['failure_cause'] = $iCause;
                $paramActualizar['failure_cause_txt'] = $sCauseTxt;
            }
            
            $this->campania->agregarTiempoContestar($iSegundosEspera);

            if (!is_null($this->agente_agendado)) {
                $a = $this->agente_agendado;
                $this->agente_agendado = NULL;
                $a->llamada_agendada = NULL;
                
                /* Se debe quitar la reservación únicamente si no hay más
                 * llamadas agendadas para este agente. Si se cumple esto,
                 * CampaignProcess lanzará el evento quitarReservaAgente
                 * luego de quitar la pausa del agente. */
                $this->_tuberia->msg_CampaignProcess_verificarFinLlamadasAgendables(
                    $a->channel, $this->campania->id, $a->resumenSeguimiento());
            }
            
            // Remover llamada que no se pudo colocar
            $this->_listaLlamadas->remover($this);                    
        } else {
            // Verificar si Onnewchannel procesó pata equivocada
            if ($this->uniqueid != $uniqueid) {
                $this->_log->output("ERR: se procesó pata equivocada en evento Newchannel ".
                    "anterior, pata procesada es {$this->uniqueid}, ".
                    "pata real es {$uniqueid}");      

                $this->unregisterAuxChannels();
                $this->AuxChannels = array();
                $this->uniqueid = $uniqueid;
                $paramActualizar['Uniqueid'] = $this->uniqueid;
            }

            /*
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: llamada colocada luego de $iSegundosEspera s. de espera."); 
            }
            */
        }
        
        // Actualizar asíncronamente las propiedades de la llamada
        $this->_tuberia->msg_CampaignProcess_sqlupdatecalls($paramActualizar);
    }
    
    public function llamadaEntraEnCola($timestamp, $channel, $sQueueNumber)
    {
        $this->timestamp_enterqueue = $timestamp;
        $this->_queuenumber = $sQueueNumber;
        if (is_null($this->channel)) $this->channel = $channel;
        if (in_array($this->status, array('Placing', 'Ringing')))
            $this->status = 'OnQueue';
        
        if ($this->tipo_llamada == 'outgoing') {
            // Preparar propiedades a actualizar en DB
            $paramActualizar = array(
                'tipo_llamada'          =>  $this->tipo_llamada,
                'id_campaign'           =>  $this->campania->id,
                'id'                    =>  $this->id_llamada,
                
                'status'                =>  $this->status,
                'datetime_entry_queue'  =>  date('Y-m-d H:i:s', $this->timestamp_enterqueue),
            );
            $this->_tuberia->msg_CampaignProcess_sqlupdatecalls($paramActualizar);
        } else {
        	// Preparar propiedades a insertar en DB
            $paramInsertar = array(
                'tipo_llamada'          =>  $this->tipo_llamada,
                'id_campaign'           =>  is_null($this->campania) ? NULL : $this->campania->id,
                'id_queue_call_entry'   =>  $this->id_queue_call_entry,
                'callerid'              =>  $this->phone,
                'datetime_entry_queue'  =>  date('Y-m-d H:i:s', $this->timestamp_enterqueue),
                'status'                =>  'en-cola',
                'uniqueid'              =>  $this->uniqueid,
                
                // Un trunk NULL ocurre en caso de Channel Local/XXX@yyyy-zzzz
                'trunk'                 =>  is_null($this->trunk) ? '' : $this->trunk,
            );
            $this->_tuberia->msg_CampaignProcess_sqlinsertcalls($paramInsertar);
        }
    }
    
    public function llamadaEnlazadaAgente($timestamp, $agent, $sRemChannel, $uniqueid_agente)
    {
        $this->agente = $agent;
        $this->agente->asignarLlamadaAtendida($this, $uniqueid_agente);
        $this->agente_agendado = NULL;
        $this->agente->llamada_agendada = NULL;
    	
        $this->status = 'Success';
        $this->timestamp_link = $timestamp;
        if (!is_null($this->campania) && $this->campania->tipo_campania == 'outgoing')
            $this->campania->agregarTiempoContestar($this->duration_answer);

        /*
        if ($this->DEBUG) {
            // Desactivado porque rellena el log
            $this->_log->output("DEBUG: llamadaEnlazadaAgente: llamada  => ".print_r($this, TRUE));
        }
        */

        // El canal verdadero es más util que Local/XXX para las operaciones
        if (strpos($sRemChannel, 'Local/') === 0 && !is_null($this->channel) 
            && $sRemChannel != $this->channel) {
            $sRemChannel = $this->channel;
        }
        if (strpos($sRemChannel, 'Local/') === 0 && !is_null($this->actualchannel) 
            && $sRemChannel != $this->actualchannel) {
            $sRemChannel = $this->actualchannel;
        }

        $paramActualizar = array(
            'tipo_llamada'          =>  $this->tipo_llamada,
            'id_campaign'           =>  is_null($this->campania) ? NULL : $this->campania->id,
            
            'id_agent'              =>  is_null($this->agente) ? NULL : $this->agente->id_agent,
            'duration_wait'         =>  $this->duration_wait,
        );
        $paramInsertarCC = array(
            'tipo_llamada'      =>  $this->tipo_llamada,
            
            'uniqueid'          =>  $this->uniqueid,
            'ChannelClient'     =>  $sRemChannel,
        );
        if ($this->tipo_llamada == 'incoming') {
        	$paramActualizar['status'] = 'activa';
            $paramActualizar['datetime_init'] = date('Y-m-d H:i:s', $this->timestamp_link);
            $paramInsertarCC['datetime_init'] = date('Y-m-d H:i:s', $this->timestamp_link);
            $paramInsertarCC['id_agent'] = $this->agente->id_agent;
            $paramInsertarCC['callerid'] = $this->phone;
            $paramInsertarCC['id_queue_call_entry'] = $this->id_queue_call_entry;
        } else {
            $paramActualizar['status'] = $this->status;
            $paramActualizar['start_time'] = date('Y-m-d H:i:s', $this->timestamp_link);
        	$paramActualizar['inc_retries'] = 1;
            $paramInsertarCC['fecha_inicio'] = date('Y-m-d H:i:s', $this->timestamp_link);
            $paramInsertarCC['queue'] = $this->campania->queue;
            $paramInsertarCC['agentnum'] = $this->agente->number;
            $paramInsertarCC['event'] = 'Link';
            $paramInsertarCC['Channel'] = $this->agente->channel;
        }

        if (!is_null($this->id_llamada)) {
            // Ya se tiene el ID de la llamada
            $paramActualizar['id'] = $this->id_llamada;
            $paramInsertarCC[($this->tipo_llamada == 'incoming') ? 'id_call_entry' : 'id_call'] = 
                $this->id_llamada;
            $this->_tuberia->msg_CampaignProcess_sqlupdatecalls($paramActualizar);
            $this->_tuberia->msg_CampaignProcess_sqlinsertcurrentcalls($paramInsertarCC);
    
            // Lanzar evento ECCP en ECCPProcess
            $this->_tuberia->msg_ECCPProcess_AgentLinked($this->tipo_llamada, 
                is_null($this->campania) ? NULL : $this->campania->id, 
                $this->id_llamada, $this->agente->channel, $sRemChannel, 
                date('Y-m-d H:i:s', $this->timestamp_link));
        } else {
        	/* En el caso de llamadas entrantes, puede ocurrir que el evento 
             * Link se reciba ANTES de haber recibido el ID de inserción en
             * call_entry. Entonces no se puede mandar a actualizar hasta tener
             * este ID, ni tampoco lanzar el evento AgentLinked. Se delegan las
             * actualizaciones hasta que se asigne a la propiedad id_llamada. */
            $this->_actualizacionesPendientes['sqlupdatecalls'] = $paramActualizar;
            $this->_actualizacionesPendientes['sqlinsertcurrentcalls'] = $paramInsertarCC;
            $this->_log->output('INFO: '.__METHOD__.': actualizaciones pendientes por faltar id_llamada.');
        }

        // Actualizar el canal remoto en caso de que no se conozca a estas alturas
        if (is_null($this->actualchannel))
            $this->_tuberia->msg_CampaignProcess_actualizarCanalRemoto(
                $this->agente->number, $this->tipo_llamada, $this->uniqueid);
    }
    
    public function llamadaRegresaHold($iTimestamp, $uniqueid_nuevo = NULL)
    {
        if (is_null($uniqueid_nuevo)) $uniqueid_nuevo = $this->_uniqueid;
        if (is_null($this->_uniqueid) || $uniqueid_nuevo != $this->_uniqueid) {
            if (!is_null($this->_uniqueid))
                $this->_listaLlamadas->removerIndice('uniqueid', $this->_uniqueid);
            $this->_uniqueid = $uniqueid_nuevo;
            $this->_listaLlamadas->agregarIndice('uniqueid', $this->_uniqueid, $this);
        }
        
        if (!is_null($this->agente)) {
            $a = $this->agente;
            $this->_tuberia->msg_ECCPProcess_marcarFinalHold(
                $iTimestamp, $a->channel,
                $this->resumenLlamada(), 
                $a->resumenSeguimiento());
            $a->clearHold();
        }

        // Actualizar el Uniqueid en la base de datos
        $this->_status = 'Success';
        if (!is_null($this->_id_llamada)) {
            $paramActualizar = array(
                'tipo_llamada'  =>  $this->tipo_llamada,
                'id_campaign'   =>  is_null($this->campania) ? NULL : $this->campania->id,
                'id'            =>  $this->_id_llamada,
                'uniqueid'      =>  $this->_uniqueid,
                'status'        =>  ($this->tipo_llamada == 'incoming') ? 'activa' : 'Success',
            );
            $this->_tuberia->msg_CampaignProcess_sqlupdatecalls($paramActualizar);
        }
        if (!is_null($this->id_current_call)) {
            $paramActualizar = array(
                'tipo_llamada'  =>  $this->tipo_llamada,
                'id'            =>  $this->id_current_call,
                'uniqueid'      =>  $this->_uniqueid,
                'hold'          =>  'N',
            );
            $this->_tuberia->msg_CampaignProcess_sqlupdatecurrentcalls($paramActualizar);
        }
    }
    
    public function llamadaFinalizaSeguimiento($timestamp, $iUmbralLlamadaCorta)
    {
        if (is_null($this->id_llamada)) {
        	$this->_log->output('ERR: '.__METHOD__.': todavía no ha llegado '.
                'ID de llamada, no se garantiza integridad de datos para esta llamada.');
        }

    	$this->timestamp_hangup = $timestamp;
        
        // Mandar a borrar el registro de current_calls
        if (!is_null($this->id_current_call)) {
            $this->_tuberia->msg_CampaignProcess_sqldeletecurrentcalls(array(
                'tipo_llamada'      =>  $this->tipo_llamada,
                'id'                =>  $this->id_current_call,
            ));
        } elseif (isset($this->_actualizacionesPendientes['sqlinsertcurrentcalls'])) {
            unset($this->_actualizacionesPendientes['sqlinsertcurrentcalls']);
        }
        $this->id_current_call = NULL;

        $paramActualizar = array(
            'tipo_llamada'          =>  $this->tipo_llamada,
            'id_campaign'           =>  is_null($this->campania) ? NULL : $this->campania->id,
        );

        /* Si la llamada nunca fue enlazada, entonces se actualiza el tiempo de
         * contestado entre hangup y el inicio del Originate */
        if (is_null($this->timestamp_link)) {
        	if ($this->tipo_llamada == 'outgoing') {
                $this->campania->agregarTiempoContestar($this->timestamp_hangup - $this->timestamp_originatestart);
                $paramActualizar['inc_retries'] = 1;
            }
            if (is_null($this->timestamp_enterqueue)) {
            	// Llamada nunca fue contestada
                $paramActualizar['status'] = 'NoAnswer';
            } else {
            	// Llamada entró a cola pero fue abandonada antes de enlazarse
                if ($this->tipo_llamada == 'incoming') {
                	$paramActualizar['status'] = 'abandonada';
                    $paramActualizar['datetime_end'] = date('Y-m-d H:i:s', $this->timestamp_hangup);
                } else {
                    $paramActualizar['status'] = 'Abandoned';
                    $paramActualizar['end_time'] = date('Y-m-d H:i:s', $this->timestamp_hangup);
                }
                $paramActualizar['duration_wait'] = $this->timestamp_hangup - $this->timestamp_enterqueue;
            }
        } else {
        	// Llamada fue enlazada normalmente
            $paramActualizar['duration'] = $this->duration;
            if ($this->tipo_llamada == 'outgoing') {
                $paramActualizar['end_time'] = date('Y-m-d H:i:s', $this->timestamp_hangup);
                if ($this->duration <= $iUmbralLlamadaCorta) {
                	$this->status = 'ShortCall';
                    $paramActualizar['status'] = 'ShortCall';
                } else {
                    $this->status = 'Hangup';
                    $this->campania->actualizarEstadisticas($this->duration);
                }
            } else {
                $paramActualizar['datetime_end'] = date('Y-m-d H:i:s', $this->timestamp_hangup);
            	$paramActualizar['status'] = 'terminada';
            }
        }
        if (!is_null($this->id_llamada)) {
            $paramActualizar['id'] = $this->id_llamada;
            $this->_tuberia->msg_CampaignProcess_sqlupdatecalls($paramActualizar);

            if (!is_null($this->agente)) {
                $this->_tuberia->msg_ECCPProcess_AgentUnlinked(
                    $this->agente->channel, $this->tipo_llamada, 
                    is_null($this->campania) ? NULL : $this->campania->id, $this->id_llamada, $this->phone);
            }
        }
        
        if (!is_null($this->agente_agendado)) {
            // Sacar de pausa al agente cuya llamada ha terminado
            $a = $this->agente_agendado;
            if ($a->reservado) {
                /* Se debe quitar la reservación únicamente si no hay más
                 * llamadas agendadas para este agente. Si se cumple esto,
                 * CampaignProcess lanzará el evento quitarReservaAgente
                 * luego de quitar la pausa del agente. */
                $this->_tuberia->msg_CampaignProcess_verificarFinLlamadasAgendables(
                    $a->channel, $this->campania->id, $a->resumenSeguimiento());
            }
        }
        $this->agente_agendado = NULL;
        if (!is_null($this->agente)) {
            $a = $this->agente;
            $this->agente->llamada_agendada = NULL;
            $this->agente->quitarLlamadaAtendida();
            $this->agente = NULL;

            if ($a->reservado) {
                /* Se debe quitar la reservación únicamente si no hay más
                 * llamadas agendadas para este agente. Si se cumple esto,
                 * CampaignProcess lanzará el evento quitarReservaAgente
                 * luego de quitar la pausa del agente. */
                $this->_tuberia->msg_CampaignProcess_verificarFinLlamadasAgendables(
                    $a->channel, $this->campania->id, $a->resumenSeguimiento());
            }
        }
    
        $this->_listaLlamadas->remover($this);
    }
}
?>