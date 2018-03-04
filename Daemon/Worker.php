<?php
namespace Hilos\Daemon;

class Worker {
  protected static $stopSignal = false;

  private $indexWorker = null;
  protected $adminEmail = null;
  private $masterPort = null;

  protected function getIndexWorker() {
    return $this->indexWorker;
  }

  public function __construct($masterPort) {
    $opts = getopt('', ['index:']);
    if (!isset($opts['index'])) {
      throw new \Exception('Missed required param "index"');
    }
    $this->indexWorker = intval($opts['index']);
    $this->masterPort = $masterPort;
  }

  protected function tick() {
    // TODO: process tasks here
  }

  public function run() {

    $this->initPcntl();

    $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($master, '127.0.0.1', $this->masterPort);
    socket_set_nonblock($master);
    $unparsedString = '';

    try {
      while (!self::$stopSignal) {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
          pcntl_signal_dispatch();
        }
        $read = [$master];
        $write = $except = null;
        $numChangedStreams = @socket_select($read, $write, $except, 0, 250000);
        if ($numChangedStreams === false) {
          if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            pcntl_signal_dispatch();
          }
          continue;
        } elseif ($numChangedStreams === 0) {
          if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            pcntl_signal_dispatch();
          }
        } else {
          $unparsedString .= socket_read($master, 1024 * 1024 * 32);
          $lines = explode(PHP_EOL, $unparsedString);
          if (count($lines) > 1) {
            $lines_processed = 0;
            foreach ($lines as $line) {
              if ($lines_processed == count($lines) - 1) break;
              if (empty($line)) continue;
              $json = json_decode($line, true);
              if ($json === false) {
                error_log($this->hilos_microtime().'|'.$this->indexWorker . ': invalid json at line "' . $line . '"');
                continue;
              }
              if (!isset($json['worker_action'])) {
                error_log($this->hilos_microtime().'|'.$this->indexWorker . ': not found param worker_action at line "' . $line . '"');
                continue;
              }
              switch ($json['worker_action']) {
                case 'task_add':
                  $task_manager_worker->task_add($json['task_index'], $json['task_class'], $json['params']);
                  break;
                case 'task_delete':
                  if (!isset($json['task_index'])) {
                    error_log($this->hilos_microtime().'|'.$this->indexWorker . ': task delete "' . $json['action'] . '" missed index param');
                    break;
                  }
                  $task_manager_worker->task_delete($json['task_index']);
                  break;
                case 'task_action':
                  if (!isset($json['task_index'])) {
                    error_log($this->hilos_microtime().'|'.$this->indexWorker . ': action "' . $json['action'] . '" missed index param');
                    break;
                  }
                  $task_manager_worker->task_action($json['task_index'], $json['action'], $json['params']);
                  break;
                case 'task_system':
                  $task_manager_worker->task_system($json['action'], $json['params']);
                  break;
                default:
                  throw new \Exception('Unknow worker_action');
                  break;
              }
              $lines_processed++;
            }
            $unparsedString = $lines[count($lines) - 1];
          }
        }
        $this->tick();
      }
    } catch (\Exception $e) {
      error_log($e->getMessage());
      error_log($e->getTraceAsString());
      print($e->getMessage());
      print($e->getTraceAsString());
      if ($this->adminEmail !== null) {
        mail($this->adminEmail, 'Hilos master Exception "'.$e->getMessage().'"', $e->getTraceAsString());
      }
    }
  }

  protected function initPcntl() {
    if (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') return;

    function signal_handler_worker($signo) {
      switch ($signo) {
        case SIGTERM:
          error_log('SIGTERM');
          // NOTE: handle stop tasks
          self::$stopSignal = true;
          break;
        case SIGHUP:
          error_log('SIGHUP');
          // NOTE: handle restart tasks
          self::$stopSignal = true;
          break;
        case SIGINT:
          error_log('SIGINT');
          // NOTE: handle exit tasks
          self::$stopSignal = true;
          break;
      }
    }

    pcntl_signal(SIGTERM, 'Hilos\\Daemon\\signal_handler_worker');
    pcntl_signal(SIGHUP, 'Hilos\\Daemon\\signal_handler_worker');
  }
}
