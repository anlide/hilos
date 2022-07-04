<?php
namespace Hilos\Daemon;

use Exception;
use Hilos\Daemon\Task\Worker as TaskWorker;

/**
 * Class Worker
 * @package Hilos\Daemon
 */
abstract class Worker {
  const WRITE_DELAY_TIMEOUT = 10; // TODO: Rename it
  const WRITE_NON_DELAY_ATTEMPTS = 100;
  const WRITE_BUFFER_SIZE = 65536 - 1;

  public static bool $stopSignal = false;

  private ?string $indexWorker = null;
  protected ?string $adminEmail = null;
  private ?int $masterPort = null;

  /** @var resource */
  private $master = null;

  /** @var TaskWorker[][] */
  protected array $tasks = [];

  /** @var string[] */
  protected array $delayWrite = [];
  protected ?bool $failedStart = null;

  protected function getIndexWorker(): string {
    return $this->indexWorker;
  }

  /**
   * Worker constructor.
   *
   * @param int $masterPort
   * @throws Exception
   */
  public function __construct(int $masterPort) {
    $opts = getopt('', ['index:']);
    if (!isset($opts['index'])) {
      $indexWorker = stream_get_line(STDIN, 32, PHP_EOL);
      if (empty($indexWorker)) {
        throw new Exception('Missed required param "index"');
      }
      $this->indexWorker = intval(substr($indexWorker, 6));
    } else {
      $this->indexWorker = intval($opts['index']);
    }
    $this->masterPort = $masterPort;
  }

  /**
   * @throws Exception
   */
  protected function tick() {
    foreach ($this->tasks as $type => &$tasks) {
      foreach ($tasks as $index => &$task) {
        $this->tasks[$type][$index]->tick();
      }
      unset($task);
    }
    unset($tasks);

    if (count($this->delayWrite) != 0) {
      if ($this->failedStart === null) $this->failedStart = time();
      $delayWrite = $this->delayWrite;
      $this->delayWrite = [];
      $crashedState = false;
      foreach ($delayWrite as $data) {
        if ($crashedState) {
          $this->delayWrite[] = $data;
        } else {
          if ($this->writeData($data) === false) {
            $crashedState = true;
          }
        }
      }
      if (count($this->delayWrite) == 0) {
        $this->failedStart = null;
      } else {
        if (time() - $this->failedStart > self::WRITE_DELAY_TIMEOUT) {
          error_log('Failed to write more than '.self::WRITE_DELAY_TIMEOUT.' seconds');
          $this->failedStart = time();
        }
      }
    }
  }

  /**
   * @throws Exception
   */
  public function run() {
    $this->initPcntl();

    $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($this->master, 'localhost', $this->masterPort);
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
          do {
            $lastRead = @socket_read($this->master, 1024 * 1024 * 32);
            $unparsedString .= $lastRead;
          } while (strlen($lastRead) > 0);
          if (in_array(socket_last_error(), [107])) {
            self::$stopSignal = true;
            continue;
          } elseif (in_array(socket_last_error(), [11, 10035])) {
            // Nothing
            // TODO: implement reconnect on "10053" error
          } elseif (socket_last_error() != 0) {
            error_log('Socket read error: '.socket_last_error());
            self::$stopSignal = true;
            continue;
          }
          $lines = explode(PHP_EOL, $unparsedString);
          if (count($lines) > 1) {
            foreach ($lines as $lineIndex => $line) {
              if (empty($line)) {
                unset($lines[$lineIndex]);
                continue;
              }
              $json = json_decode($line, true);
              if ($json === null) {
                error_log($this->indexWorker . ': invalid json with lines "' . count($lines) . '"');
                continue;
              }
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
                  $this->taskAction($json['task_type'], $json['task_index'], $json['action'], $json['params'] ?? null);
                  break;
                case 'task_system':
                  $this->taskSystem($json['action'], $json['params']);
                  break;
                default:
                  throw new Exception('Unknown worker_action');
              }
              unset($lines[$lineIndex]);
              break;
            }
            $unparsedString = implode(PHP_EOL, $lines);
          }
        }
        $this->tick();
      }
    } catch (Exception $e) {
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

  protected abstract function getTaskByType($type, $index = null): ?TaskWorker;

  /**
   * @param $json
   * @throws Exception
   */
  protected function write($json) {
    if (count($this->delayWrite) == 0) {
      $this->writeData(json_encode($json) . PHP_EOL);
    } else {
      $this->delayWrite[] = json_encode($json) . PHP_EOL;
    }
  }

  /**
   * @param $data
   * @return bool|int
   * @throws Exception
   */
  private function writeData($data) {
    $bytesLeft = $total = strlen($data);
    $tryCount = 0;
    do {
      socket_clear_error($this->master);
      $sent = @socket_write($this->master, $data, self::WRITE_BUFFER_SIZE);
      if ($sent === false) {
        switch (socket_last_error()) {
          case SOCKET_EAGAIN:
          case SOCKET_EWOULDBLOCK:
            $this->delayWrite[] = $data;

            return false;
          case SOCKET_EPIPE:
          case SOCKET_ECONNABORTED: // TODO: implement reconnect for this error
          case SOCKET_ECONNRESET: // TODO: implement reconnect for this error
            throw new Exception('Unable to write to master ['.socket_strerror(socket_last_error()).'/'.socket_last_error().']');
          default:
            error_log('Hilos socket to master write error [' . socket_last_error() . '] : ' . socket_strerror(socket_last_error()));
        }
      } else {
        $bytesLeft -= $sent;
        $data = substr($data, $sent);
      }
      $tryCount++;
      if ($tryCount >= self::WRITE_NON_DELAY_ATTEMPTS) {
        $this->delayWrite[] = $data;

        return false;
      }
    } while ($bytesLeft > 0);

    return $total;
  }

  /**
   * @param string $type
   * @param string|int|array|null $index
   */
  protected function taskAdd(string $type, $index) {
    $indexString = is_array($index) ? implode('-', $index) : $index;
    if (!isset($this->tasks[$type])) {
      $this->tasks[$type] = [];
    }
    if (isset($this->tasks[$type][$indexString])) {
      return;
    }
    $this->tasks[$type][$indexString] = $this->getTaskByType($type, $index);
  }

  /**
   * @param string $type
   * @param string|int|array|null $index
   */
  protected function taskDelete(string $type, $index) {
    $indexString = is_array($index) ? implode('-', $index) : $index;
    error_log('taskDelete'.$type.$indexString);
  }

  /**
   * @param string $type
   * @param string|int|array|null $index
   * @param string $action
   * @param null|array $params
   */
  protected function taskAction(string $type, $index, string $action, ?array $params) {
    $indexString = is_array($index) ? implode('-', $index) : $index;
    if (!isset($this->tasks[$type])) {
      error_log('DEBUG: taskAction: Not exists task type '.$type);
      return;
    }
    if (!isset($this->tasks[$type][$indexString])) {
      error_log('DEBUG: taskAction: Not exists task '.$type.' / '.$indexString);
      return;
    }
    $this->tasks[$type][$indexString]->onAction($action, $params);
  }

  protected function taskSystem($action, $params) {
    error_log('taskSystem'.$action.json_encode($params));
  }
}
