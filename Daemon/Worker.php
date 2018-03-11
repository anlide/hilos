<?php
namespace Hilos\Daemon;

use Hilos\Daemon\Task\Worker as TaskWorker;

abstract class Worker {
  protected static $stopSignal = false;

  private $indexWorker = null;
  protected $adminEmail = null;
  private $masterPort = null;
  /** @var resource */
  private $master = null;

  /** @var TaskWorker[][] */
  protected $tasks = [];

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
    foreach ($this->tasks as $type => &$tasks) {
      foreach ($tasks as $index => &$task) {
        $this->tasks[$type][$index]->tick();
      }
      unset($task);
    }
    unset($tasks);
  }

  public function run() {

    $this->initPcntl();

    $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($this->master, '127.0.0.1', $this->masterPort);
    socket_set_nonblock($this->master);
    $unparsedString = '';

    $this->write(['index_worker' => $this->indexWorker]);

    try {
      while (!self::$stopSignal) {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
          pcntl_signal_dispatch();
        }
        $read = [$this->master];
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
          socket_clear_error($this->master);
          $unparsedString .= socket_read($this->master, 1024 * 1024 * 32);
          if (in_array(socket_last_error(), array(107))) {
            self::$stopSignal = true;
            continue;
          }
          $lines = explode(PHP_EOL, $unparsedString);
          if (count($lines) > 1) {
            $lines_processed = 0;
            foreach ($lines as $line) {
              if ($lines_processed == count($lines) - 1) break;
              if (empty($line)) continue;
              $json = json_decode($line, true);
              if ($json === false) {
                error_log($this->indexWorker . ': invalid json at line "' . $line . '"');
                continue;
              }
              if (!isset($json['worker_action'])) {
                error_log($this->indexWorker . ': not found param worker_action at line "' . $line . '"');
                continue;
              }
              switch ($json['worker_action']) {
                case 'task_add':
                  if (!isset($json['task_type'])) {
                    error_log($this->indexWorker . ': task add "' . $json['worker_action'] . '" missed type param');
                    break;
                  }
                  if (!isset($json['task_index'])) {
                    error_log($this->indexWorker . ': task add "' . $json['worker_action'] . '" missed index param');
                    break;
                  }
                  $this->taskAdd($json['task_type'], $json['task_index']);
                  break;
                case 'task_delete':
                  if (!isset($json['task_type'])) {
                    error_log($this->indexWorker . ': task delete "' . $json['worker_action'] . '" missed type param');
                    break;
                  }
                  if (!isset($json['task_index'])) {
                    error_log($this->indexWorker . ': task delete "' . $json['worker_action'] . '" missed index param');
                    break;
                  }
                  $this->taskDelete($json['task_type'], $json['task_index']);
                  break;
                case 'task_action':
                  if (!isset($json['task_type'])) {
                    error_log($this->indexWorker . ': task action "' . $json['worker_action'] . '" missed type param');
                    break;
                  }
                  if (!isset($json['task_index'])) {
                    error_log($this->indexWorker . ': task action "' . $json['worker_action'] . '" missed index param');
                    break;
                  }
                  if (!isset($json['action'])) {
                    error_log($this->indexWorker . ': task action "' . $json['worker_action'] . '" missed action param');
                    break;
                  }
                  $this->taskAction($json['task_type'], $json['task_index'], $json['action'], isset($json['params']) ? $json['params'] : null);
                  break;
                case 'task_system':
                  $this->taskSystem($json['action'], $json['params']);
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
          Worker::$stopSignal = true;
          break;
        case SIGHUP:
          error_log('SIGHUP');
          // NOTE: handle restart tasks
          Worker::$stopSignal = true;
          break;
        case SIGINT:
          error_log('SIGINT');
          // NOTE: handle exit tasks
          Worker::$stopSignal = true;
          break;
      }
    }

    pcntl_signal(SIGTERM, 'Hilos\\Daemon\\signal_handler_worker');
    pcntl_signal(SIGHUP, 'Hilos\\Daemon\\signal_handler_worker');
  }

  protected abstract function getTaskByType($type, $index = null):TaskWorker;

  protected function write($json) {
    socket_write($this->master, json_encode($json).PHP_EOL);
  }

  protected function taskAdd($type, $index) {
    if (!isset($this->tasks[$type])) {
      $this->tasks[$type] = [];
    }
    if (isset($this->tasks[$type][$index])) {
      return;
    }
    $this->tasks[$type][$index] = $this->getTaskByType($type, $index);
  }

  protected function taskDelete($type, $index) {
    error_log('taskDelete'.$type.$index);
  }

  protected function taskAction($type, $index, $action, $params) {
    if (!isset($this->tasks[$type])) return;
    if (!isset($this->tasks[$type][$index])) return;
    $this->tasks[$type][$index]->onAction($action, $params);
  }

  protected function taskSystem($action, $params) {
    error_log('taskSystem'.$action.json_encode($params));
  }
}
