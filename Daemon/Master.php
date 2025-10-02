<?php
namespace Hilos\Daemon;

use Exception;
use Hilos\Daemon\Client\IClient;
use Hilos\Daemon\Exception\InvalidSituation;
use Hilos\Daemon\Exception\SocketSelect;
use Hilos\Daemon\Server\IServer;
use Hilos\Daemon\Server\Worker as ServerWorker;
use Hilos\Daemon\Task\Master as TaskMaster;
use Hilos\Database\Migration;

/**
 * Class Master
 * @package Hilos\Daemon
 */
abstract class Master {
  const MICROSECONDS_PER_SECOND = 1000000;
  const TICK_INTERVAL_SECONDS = 0.1;
  const TICK_INTERVAL_MICROSECONDS = self::TICK_INTERVAL_SECONDS * self::MICROSECONDS_PER_SECOND;
  const STOP_SIGNAL_SLEEP_MICROSECONDS = 10000;

  public static bool $stopSignal = false;
  public static bool $forceStop = false;
  public static ?int $stopTime = null;

  protected ?string $adminEmail;

  /** @var int */
  protected int $stopTimeout = 10;

  /** @var IServer[] */
  protected array $servers = [];

  /** @var IClient[] */
  protected array $clients = [];

  /** @var ServerWorker|null */
  protected ?ServerWorker $serverWorker = null;

  /** @var TaskMaster[] */
  protected array $tasks = [];

  protected array $sockets;

  protected array $willStartServers = [];

  /**
   * @param IServer $server
   * @throws Exception
   */
  public function registerServer(IServer $server) {
    $this->servers[] = $server;
    if ($server instanceof ServerWorker) {
      if ($this->serverWorker !== null) {
        throw new Exception('Unable init server worker twice');
      }
      $this->serverWorker = $server;
    }
  }

  /**
   * @param $taskType
   * @param $taskIndex
   * @return ?TaskMaster
   * @throws Exception
   */
  protected function getTaskByType($taskType, $taskIndex): ?TaskMaster {
    throw new Exception('getTaskByType not implemented at final class');
  }

  /**
   * @param string $taskType
   * @param mixed|null $taskIndex
   * @return TaskMaster
   * @throws Exception
   */
  public function taskGet(string $taskType, mixed $taskIndex = null): TaskMaster {
    $taskIndexString = (is_array($taskIndex)) ? implode('-', $taskIndex) : $taskIndex;
    if ($this->serverWorker === null) throw new Exception('Server Worker not registered');
    if (!isset($this->tasks[$taskType . '-' . $taskIndexString])) {
      $this->tasks[$taskType . '-' . $taskIndexString] = $this->getTaskByType($taskType, $taskIndex);
      $this->serverWorker->addTask($this->tasks[$taskType . '-' . $taskIndexString]);
      $this->onTaskAdd($this->tasks[$taskType . '-' . $taskIndexString]);
    }

    return $this->tasks[$taskType . '-' . $taskIndexString];
  }

  /**
   * @param TaskMaster $task
   * @return void
   */
  protected function onTaskAdd(TaskMaster $task): void
  {
  }

  /**
   * @param $taskType
   * @return array
   * @throws Exception
   */
  public function taskGetsByType($taskType): array {
    $ret = [];
    if ($this->serverWorker === null) throw new Exception('Server Worker not registered');
    foreach ($this->tasks as $task) {
      if ($task->getTaskType() != $taskType) continue;
      $ret[] = $task;
    }

    return $ret;
  }

  /**
   * @param $initialFile
   * @param int|null $count
   * @param int $monopoly
   * @throws Exception
   */
  public function runWorkers($initialFile, ?int $count = null, int $monopoly = 0): void
  {
    if ($count === null) $count = $this->getProcessorCount();
    if ($this->serverWorker === null) throw new Exception('Server Worker not registered');
    if ($count < 1) throw new Exception('Invalid processors count');
    if ($count <= $monopoly) throw new Exception('Mount of workers should be more then monopoly-workers');
    if (!empty($this->workers)) throw new Exception('Trying to run workers twice');
    $this->serverWorker->runWorkers($initialFile, $count, $monopoly);
  }

  protected function tick(): void
  {
    foreach ($this->servers as &$server) {
      $server->tick();
    }
    unset($server);
    foreach ($this->clients as &$client) {
      $client->tick();
    }
    unset($client);
  }

  public function run() {
    $sockets = [];
    $this->sockets = &$sockets;

    $this->initPcntl();

    try {
      foreach ($this->servers as $index => $server) {
        $socket = $server->autoStart();
        if ($socket !== null) {
          $sockets['master-'.$index] = $socket;
        }
      }
      $remainingTime = 0; // Initialize remaining time for first iteration
      
      while (!self::$forceStop) {
        $loopStartTime = microtime(true);
        
        if ((self::$stopTime !== null) && (time() - self::$stopTime > $this->stopTimeout)) {
          error_log('Stop timeout');
          break;
        }
        if (Master::$stopSignal) {
          usleep(self::STOP_SIGNAL_SLEEP_MICROSECONDS);
        }
        if (!empty($this->willStartServers)) {
          foreach ($this->servers as $index => $server) {
            if (!in_array(get_class($server), $this->willStartServers)) continue;
            $sockets['master-'.$index] = $server->start();
            error_log('Server '.get_class($server).' start');
            var_dump('Server '.get_class($server).' start');
          }
          $this->willStartServers = [];
        }
        $read = $sockets;
        $write = $except = array();
        
        // Calculate timeout based on processing time
        $timeoutMicroseconds = 0;
        if ($remainingTime > 0) {
          $timeoutMicroseconds = (int)($remainingTime * self::MICROSECONDS_PER_SECOND);
        } else {
          $timeoutMicroseconds = self::TICK_INTERVAL_MICROSECONDS;
        }
        
        if ((@socket_select($read, $write, $except, 0, $timeoutMicroseconds)) === false) {
          $this->dispatchPcntl();
          if (Master::$forceStop) {
            continue;
          }
          if (socket_last_error() === SOCKET_EINTR) {
            error_log('Hilos master socket_select interrupted. Going to stop...');
            Master::$stopSignal = true;
          } elseif (socket_last_error() !== SOCKET_EWOULDBLOCK) {
            if ($this->adminEmail !== null) mail($this->adminEmail, 'Hilos master socket_select error', socket_strerror(socket_last_error()).' / '.socket_last_error());
            error_log('Hilos master socket_select error "' . socket_strerror(socket_last_error()) . '" / ' . socket_last_error());
            throw new SocketSelect(socket_last_error() . '/ ' . socket_strerror(socket_last_error()));
          }
          continue;
        }
        foreach ($read as $socket) {
          $indexSocket = array_search($socket, $sockets);
          if ($indexSocket === false) throw new InvalidSituation('Not exists socket index');
          if (substr($indexSocket, 0, 7) == 'master-') {
            $indexSocketServer = substr($indexSocket, 7);
            $this->acceptNewClient($indexSocketServer);
            end($this->clients);
            $indexSocketClient = key($this->clients);
            $client = &$this->clients[$indexSocketClient];
            $sockets['client-'.$indexSocketClient] = $client->getSocket();
            unset($client);
          }
          if (substr($indexSocket, 0, 7) == 'client-') {
            $indexSocketClient = substr($indexSocket, 7);
            $this->handleClient($indexSocketClient);
            if ($this->clients[$indexSocketClient]->closed()) {
              $this->closeClient($indexSocketClient);
              unset($sockets['client-'.$indexSocketClient]);
            }
          }
        }
        $this->tick();
        $this->dispatchPcntl();
        
        // Calculate remaining time and adjust timeout for next iteration
        $processingTime = microtime(true) - $loopStartTime;
        if ($processingTime < self::TICK_INTERVAL_SECONDS) {
          $remainingTime = self::TICK_INTERVAL_SECONDS - $processingTime;
        } else {
          $remainingTime = 0;
        }
      }
      foreach ($this->clients as &$client) {
        $client->stop();
      }
      unset($client);
      foreach ($this->servers as &$server) {
        $server->stop();
      }
      unset($server);
    } catch (Exception $e) {
      $this->catchException($e);
      return;
    }
    $this->onFinish();
  }
  protected function onFinish(): void {
    if ($this->adminEmail !== null) {
      mail($this->adminEmail, 'Hilos master stopped', '');
    }
  }
  protected function catchException(Exception $e): void {
    error_log($e->getMessage());
    error_log($e->getTraceAsString());
    print($e->getMessage());
    print($e->getTraceAsString());
    if ($this->adminEmail !== null) {
      mail($this->adminEmail, 'Hilos master Exception "'.$e->getMessage().'"', $e->getTraceAsString());
    }
  }
  public function migration() {
    Migration::up();
  }
  public function migrationDown() {
    Migration::down();
  }
  protected function acceptNewClient($indexSocketServer) {
    $this->clients[] = $this->servers[$indexSocketServer]->accept();
  }
  protected function handleClient($indexSocketClient) {
    $this->clients[$indexSocketClient]->handle();
  }
  protected function closeClient($indexSocketClient) {
    unset($this->clients[$indexSocketClient]);
  }
  protected function initPcntl() {
    if ($this->isWindows()) return;

    function signal_handler($signo) {
      switch ($signo) {
        case SIGTERM:
          error_log('SIGTERM');
          // NOTE: handle stop tasks
          Master::$stopSignal = true;
          if (Master::$stopTime === null) {
            Master::$stopTime = time();
          }
          break;
        case SIGHUP:
          error_log('SIGHUP');
          // NOTE: handle restart tasks
          Master::$stopSignal = true;
          if (Master::$stopTime === null) {
            Master::$stopTime = time();
          }
          break;
        case SIGINT:
          error_log('SIGINT');
          // NOTE: handle exit tasks
          if (Master::$stopTime === null) {
            Master::$stopTime = time();
          }
          if (Master::$stopSignal) {
            Master::$forceStop = true;
          } else {
            Master::$stopSignal = true;
          }
          break;
      }
    }

    pcntl_signal(SIGTERM, 'Hilos\\Daemon\\signal_handler');
    pcntl_signal(SIGHUP, 'Hilos\\Daemon\\signal_handler');
    pcntl_signal(SIGINT, 'Hilos\\Daemon\\signal_handler');
  }
  protected function dispatchPcntl(): void
  {
    if ($this->isWindows()) return;

    pcntl_signal_dispatch();
  }

  protected function isWindows(): bool {
    return strtolower(substr(PHP_OS, 0, 3)) == 'win';
  }
  protected function getProcessorCount(): int
  {
    // TODO: implement this feature for windows correctly
    if ($this->isWindows()) {
      return 3;
    } else {
      exec('cat /proc/cpuinfo | grep ^processor |wc -l', $output);
      return intval($output[0]);
    }
  }
}