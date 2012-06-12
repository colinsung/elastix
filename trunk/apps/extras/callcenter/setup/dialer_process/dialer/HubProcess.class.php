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

class HubProcess extends AbstractProcess
{
    private $_log;      // Log abierto por framework de demonio
    private $_config;   // Información de configuración copiada del archivo
    private $_hub;      // Hub de mensajes entre todos los procesos
    private $_tareas;   // Lista de tareas, nombreClase => PID
    
    // Último instante en que se verificó que los procesos estaban activos
    private $_iTimestampVerificacionProcesos = NULL;

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
    	$this->_log =& $oMainLog;
        $this->_config =& $infoConfig;
        $this->_tareas = array(
            'AMIEventProcess'   =>  NULL,
            'CampaignProcess'   =>  NULL,
            'ECCPProcess'       =>  NULL,
        );
        $this->_hub = new HubServer($this->_log);
        
        return TRUE;
    }
    
    /* Verificar si la tarea indicada sigue activa. Devuelve VERDADERO si la 
     * tarea sigue corriendo, FALSO si inactiva o si se detecta que terminó. */
    private function _revisarTareaActiva($sTarea)
    {
        $bTareaActiva = FALSE;

        // Si está definido el PID del proceso, se verifica si se ejecuta.
        if (!is_null($this->_tareas[$sTarea])) {
            $iStatus = NULL;
            $iPidDevuelto = pcntl_waitpid($this->_tareas[$sTarea], $iStatus, WNOHANG);
            if ($iPidDevuelto > 0) {
                $this->_log->output("WARN: $sTarea (PID=$iPidDevuelto) ha terminado inesperadamente (status=$iStatus), se agenda reinicio...");
                $iErrCode = pcntl_wifexited($iStatus) ? pcntl_wexitstatus($iStatus) : 255;
                $iRcvSignal = pcntl_wifsignaled($iStatus) ? pcntl_wtermsig($iStatus) : 0;
                if ($iRcvSignal != 0) { $this->_log->output("WARN: $sTarea terminó debido a señal $iRcvSignal..."); }
                if ($iErrCode != 0) { $this->_log->output("WARN: $sTarea devolvió código de error $iErrCode..."); }
                $this->_tareas[$sTarea] = NULL;
                
                // Quitar la tubería del proceso que ha terminado
                $this->_hub->quitarTuberia($sTarea);
            } else {
            	$bTareaActiva = TRUE;
            }
        }
        
        return $bTareaActiva;
    }
    
    public function procedimientoDemonio()
    {
        $bHayNuevasTareas = FALSE;

        // Si la tarea ha finalizado o no existe, se debe iniciar
        if (is_null($this->_iTimestampVerificacionProcesos) || time() - $this->_iTimestampVerificacionProcesos > 0) {
            foreach (array_keys($this->_tareas) as $sTarea) {
                // Si está definido el PID del proceso, se verifica si se ejecuta.
                $this->_revisarTareaActiva($sTarea);
                
                // Si no está definido el PID del proceso, se intenta iniciar
                if (is_null($this->_tareas[$sTarea])) {
                    $this->_tareas[$sTarea] = $this->_iniciarTarea($sTarea);
                    $bHayNuevasTareas = TRUE;
                }
            }
            $this->_iTimestampVerificacionProcesos = time();
        }
        
        // Registrar el multiplex con todas las conexiones nuevas
        if ($bHayNuevasTareas) $this->_hub->registrarMultiplexPadre();
        
        $this->propagarSIGHUP();
        
        // Rutear todos los mensajes pendientes entre tareas
        if ($this->_hub->procesarPaquetes())
            $this->_hub->procesarActividad(0);
        else $this->_hub->procesarActividad(1);
        
        return TRUE;
    }
    
    public function propagarSIGHUP()
    {
        global $gsNombreSignal;

        if (!is_null($gsNombreSignal) && $gsNombreSignal == SIGHUP) {
            // Mandar la señal a todos los procesos controlados
            $this->_log->output("PID = ".posix_getpid().", se ha recibido señal #$gsNombreSignal, ".
                (($gsNombreSignal == SIGHUP) ? 'cambiando logs' : 'terminando')."...");
            foreach (array_keys($this->_tareas) as $sTarea) {
                if (!is_null($this->_tareas[$sTarea])) {
                    $this->_log->output("Propagando señal #$gsNombreSignal to $sTarea...");
                    posix_kill($this->_tareas[$sTarea], $gsNombreSignal);
                    $this->_log->output("Completada propagación de señal a $sTarea");
                }
            }           
        }
    }
    
    /* Iniciar una tarea específica en un proceso separado. Para el proceso 
     * padre, devuelve el PID del proceso hijo. */
    private function _iniciarTarea($sNombreTarea)
    {
        global $gsNombreSignal;

        // Verificar que el nombre de la clase que implementa el proceso es válido
        if (!class_exists($sNombreTarea)) {
            $this->_log->output("FATAL: (internal) Invalid process classname '$sNombreTarea'");
            die("(internal) Invalid process classname '$sNombreTarea'\n");    
        }

        // Nueva tubería con el nombre de la tarea
        $oTuberia = $this->_hub->crearTuberia($sNombreTarea);
        $oTuberia->setLog($this->_log);

        // Iniciar tarea en proceso separado
        $iPidProceso = pcntl_fork();
        if ($iPidProceso != -1) {
            if ($iPidProceso == 0) {
                $this->_log->prefijo($sNombreTarea);
                $this->_log->output("iniciando proceso...");
    
                // Instalar los manejadores de señal para el proceso hijo
                pcntl_signal(SIGTERM, 'manejadorPrimarioSignal');
                pcntl_signal(SIGQUIT, 'manejadorPrimarioSignal');
                pcntl_signal(SIGINT, 'manejadorPrimarioSignal');
                pcntl_signal(SIGHUP, 'manejadorPrimarioSignal');
    
                // Elegir la tarea que debe de ejecutarse
                $oProceso = NULL;
                try {
                    $oProceso = new $sNombreTarea($oTuberia);
                    if (!($oProceso instanceof TuberiaProcess)) throw new Exception('Not a subclass of TuberiaProcess!');
                } catch (Exception $ex) {
                    $this->_log->output("ERR: al crear $sNombreTarea - excepción no manejada: ".$ex->getMessage());
                    die("ERR: al instanciar $sNombreTarea - ".$ex->getMessage()."\n");
                }
                
                // Realizar inicialización adicional de la tarea
                try {
                    $bContinuar = $oProceso->inicioPostDemonio($this->_config, $this->_log);
                    if ($bContinuar) $this->_log->output("PID = ".posix_getpid().", proceso iniciado normalmente");
                } catch (Exception $ex) {
                    $bContinuar = FALSE;
                    $this->_log->output("ERR: al inicializar $sNombreTarea - excepción no manejada: ".$ex->getMessage());
                }
                
                // Continuar la tarea hasta que se finalice
                while ($bContinuar) {
                    // Ejecutar el procedimiento de trabajo del demonio
                    if (is_null($gsNombreSignal)) {
                        try {
                            $bContinuar = $oProceso->procedimientoDemonio();
                        } catch (Exception $ex) {
                            $bContinuar = FALSE;
                            $this->_log->output("ERR: al ejecutar $sNombreTarea - excepción no manejada: ".$ex->getMessage());
                        }
                    }
                    
                    // Revisar si existe señal que indique finalización del programa
                    if (!is_null($gsNombreSignal)) {                    
                        if (in_array($gsNombreSignal, array(SIGTERM, SIGINT, SIGQUIT))) {
                            $this->_log->output("PID = ".posix_getpid().", proceso recibió señal $gsNombreSignal, terminando...");
                            $bContinuar = FALSE;
                        } elseif ($gsNombreSignal == SIGHUP) {
                            $this->_log->output("PID = ".posix_getpid().", proceso recibió señal $gsNombreSignal, cambiando logs...");
                            $this->_log->reopen();
                            $this->_log->output("PID = ".posix_getpid().", proceso recibió señal $gsNombreSignal, usando nuevo log.");
                            $gsNombreSignal = NULL;
                        }
                    }
                }
    
                // Indicar al módulo de trabajo por qué se está finalizando
                try {
                    $oProceso->limpiezaDemonio($gsNombreSignal);
                } catch (Exception $ex) {
                    $this->_log->output("ERR: al finalizar $sNombreTarea - excepción no manejada: ".$ex->getMessage());
                }
                $this->_log->output("PID = ".posix_getpid().", proceso terminó normalmente.");
                $this->_log->close();
    
                exit(0);   // Finalizar el proceso hijo
            }
        } else {
            // Avisar que no se puede iniciar la tarea requerida
            $this->_log->output("Unable to fork $sNombreTarea - $!");
        }
        return $iPidProceso;
    }
    
    public function limpiezaDemonio($signum)
    {
        // Propagar la señal si no es NULL
        if (!is_null($signum)) {
            // Mandar la señal a todos los procesos controlados
            $this->_log->output("PID = ".posix_getpid().", se ha recibido señal #$signum, terminando...");
        } else {
        	$signum = SIGTERM;
            $this->_log->output("Término normal del programa, se terminará procesos hijos...");
        }
        
        // Avisar a todos los procesos que se terminará el programa
        $this->_log->output('INFO: avisando de finalización a todos los procesos...');
        $this->_hub->enviarFinalizacion();
        $this->_log->output('INFO: esperando respuesta de todos los procesos...');
        while ($this->_hub->numFinalizados() < count(array_filter($this->_tareas))) {
            foreach (array_keys($this->_tareas) as $sTarea)
                $this->_revisarTareaActiva($sTarea);
            if ($this->_hub->procesarPaquetes())
                $this->_hub->procesarActividad(0);
            else $this->_hub->procesarActividad(1);
        }        

        // Propagar la señal recibida o sintetizada
        foreach (array_keys($this->_tareas) as $sTarea) {
            if (!is_null($this->_tareas[$sTarea])) {
                $this->_log->output("Propagando señal #$signum to $sTarea...");
                posix_kill($this->_tareas[$sTarea], $signum);
                $this->_log->output("Completada propagación de señal a $sTarea");
            }
        }
        
        $this->_log->output('INFO: esperando a que todas las tareas terminen...');
        $bTodosTerminaron = FALSE;
        do {
            $bTodosTerminaron = TRUE;
            foreach (array_keys($this->_tareas) as $sTarea) {
                // Si está definido el PID del proceso, se verifica si se ejecuta.
                if ($this->_revisarTareaActiva($sTarea)) {
                    // Este proceso aún no termina...
                    $bTodosTerminaron = FALSE;
                }
            }

            // Rutear todos los mensajes pendientes entre tareas
            if (!$this->_hub->procesarPaquetes())
                $this->_hub->procesarActividad();
        } while (!$bTodosTerminaron);
        $this->_log->output('INFO: todas las tareas han terminado.');
    	
        // Mandar a cerrar todas las conexiones activas
        $this->_hub->finalizarServidor();
    }
}
?>