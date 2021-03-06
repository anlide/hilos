<?php
namespace Hilos\Daemon;

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
  public static $stopSignal = false;

  protected $adminEmail;

  /** @var IServer[] */
  protected $servers = [];

  /** @var IClient[] */
  protected $clients = [];

  /** @var ServerWorker */
  protected $serverWorker = null;

  /** @var TaskMaster[] */
  protected $tasks = [];

  protected $sockets;

  protected $willStartServers = [];

  /**
   * @param IServer $server
   * @throws \Exception
   */
  public function registerServer(IServer $server) {
    $this->servers[] = $server;
    if ($server instanceof ServerWorker) {
      if ($this->serverWorker !== null) {
        throw new \Exception('Unable init server worker twice');
      }
      $this->serverWorker = $server;
    }
  }

  /**
   * @param $taskType
   * @param $taskIndex
   * @return TaskMaster
   * @throws \Exception
   */
  protected function getTaskByType($taskType, $taskIndex): TaskMaster {
    throw new \Exception('getTaskByType not implemented at final class');
  }

  /**
   * @param string $taskType
   * @param mixed|null $taskIndex
   * @return TaskMaster
   * @throws \Exception
   */
  public function taskGet($taskType, $taskIndex = null) {
    $taskIndexString = (is_array($taskIndex)) ? implode('-', $taskIndex) : $taskIndex;
    if ($this->serverWorker === null) throw new \Exception('Server Worker not registered');
    if (!isset($this->tasks[$taskType . '-' . $taskIndexString])) {
      $this->tasks[$taskType . '-' . $taskIndexString] = $this->getTaskByType($taskType, $taskIndex);
      $this->serverWorker->addTask($this->tasks[$taskType . '-' . $taskIndexString]);
    }
    return $this->tasks[$taskType . '-' . $taskIndexString];
  }

  /**
   * @param $taskType
   * @return array
   * @throws \Exception
   */
  public function taskGetsByType($taskType) {
    $ret = [];
    if ($this->serverWorker === null) throw new \Exception('Server Worker not registered');
    foreach ($this->tasks as $task) {
      if ($task->getTaskType() != $taskType) continue;
      $ret[] = $task;
    }
    return $ret;
  }

  /**
   * @param $initialFile
   * @param null $count
   * @throws \Exception
   */
  public function runWorkers($initialFile, $count = null) {
    if ($count === null) $count = $this->getProcessorCount();
    if ($this->serverWorker === null) throw new \Exception('Server Worker not registered');
    if ($count < 1) throw new \Exception('Invalid processors count');
    if (!empty($this->workers)) throw new \Exception('Trying to run workers twice');
    $this->serverWorker->runWorkers($initialFile, $count);
  }

  protected function tick() {
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
      while (!self::$stopSignal) {
        if (!empty($this->willStartServers)) {
          foreach ($this->servers as $index => $server) {
            if (!in_array(get_class($server), $this->willStartServers)) continue;
            $sockets['master-'.$index] = $server->start();
          }
          $this->willStartServers = [];
        }
        $read = $sockets;
        $write = $except = array();
        if ((@socket_select($read, $write, $except, 0, 1000000)) === false) {
          if (socket_strerror(socket_last_error()) != 'Interrupted system call') {
            if ($this->adminEmail !== null) mail($this->adminEmail, 'Hilos master socket_select error', socket_strerror(socket_last_error()));
            error_log('Hilos master socket_select error "' . socket_strerror(socket_last_error()) . '"');
            throw new SocketSelect(socket_last_error() . '/ ' . socket_strerror(socket_last_error()));
          }
          if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            pcntl_signal_dispatch();
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
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
          pcntl_signal_dispatch();
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
    if (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') return;

    function signal_handler($signo) {
      switch ($signo) {
        case SIGTERM:
          error_log('SIGTERM');
          // NOTE: handle stop tasks
          Master::$stopSignal = true;
          break;
        case SIGHUP:
          error_log('SIGHUP');
          // NOTE: handle restart tasks
          Master::$stopSignal = true;
          break;
        case SIGINT:
          error_log('SIGINT');
          // NOTE: handle exit tasks
          Master::$stopSignal = true;
          break;
      }
    }

    pcntl_signal(SIGTERM, 'Hilos\\Daemon\\signal_handler');
    pcntl_signal(SIGHUP, 'Hilos\\Daemon\\signal_handler');
  }

  protected function getProcessorCount() {
    // TODO: implement this feature for windows correctly
    if (strtolower(substr(PHP_OS, 0, 3)) == 'win') {
      return 3;
    } else {
      exec('cat /proc/cpuinfo | grep ^processor |wc -l', $output);
      return intval($output[0]);
    }
  }
}