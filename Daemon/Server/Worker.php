<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\Worker as ClientWorker;
use Hilos\Daemon\Exception\SocketAcceptUnable;
use Hilos\Daemon\Task\Master as TaskMaster;
use Hilos\Service\Config;

class Worker extends Server {

  protected $classWorkerClient;

  /** @var resource[] */
  protected $processes = [];

  /** @var ClientWorker[] */
  protected $clients = [];

  /** @var TaskMaster[] */
  protected $delayTasks = [];

  /**
   * @var array
   */
  protected $pipes = [];

  function __construct($port, $classWorkerClient = ClientWorker::class) {
    $this->port = $port;
    $this->classWorkerClient = $classWorkerClient;
    $this->autoStart = true;
  }

  /**
   * @param $initialFile
   * @param $count
   * @throws \Exception
   */
  public function runWorkers($initialFile, $count) {
    for ($index = 0; $index < $count; $index++) {
      $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["file", Config::env('HILOS_LOG_PATH').str_replace('%0', $index, Config::env('HILOS_WORKER_LOG_ERROR_FILE')), "a"]
      ];
      $this->pipes[$index] = null;
      $add = '';
      if (strtolower(substr(PHP_OS, 0, 3)) != 'win') $add = 'exec ';
      $this->processes[$index] = proc_open($add . Config::env('PHP_RUN_DIR') . ' ' . $initialFile, $descriptorspec, $this->pipes[$index], dirname($initialFile));
      if (!is_resource($this->processes[$index])) {
        throw new \Exception('unable to create worker');
      }
      fwrite($this->pipes[$index][0], 'index-'.$index.PHP_EOL);
    }
  }

  /**
   * @param $task
   * @param bool $delay
   * @return bool
   * @throws \Exception
   */
  public function addTask(&$task, $delay = false) {
    $minIndex = null;
    $minCount = null;
    foreach ($this->clients as $index => &$client) {
      if ($index == 0) continue;
      $count = $client->taskCount();
      if (($minCount === null) || ($minCount > $count)) {
        $minIndex = $index;
        $minCount = $count;
      }
    }
    if ($minIndex === null) {
      if (!$delay) {
        $this->delayTasks[] = $task;
      }
    } else {
      $this->clients[$minIndex]->taskAdd($task);
      return true;
    }
    return false;
  }

  /**
   * @throws \Exception
   */
  function tick() {
    if ((count($this->delayTasks) == 0) || (count($this->clients) == 0)) return;
    foreach ($this->delayTasks as $index => $task) {
      if ($this->addTask($task, true)) {
        unset($this->delayTasks[$index]);
      }
    }
    unset($task);
  }

  function stop() {
    parent::stop();
    foreach ($this->processes as $index => $process) {
      proc_terminate($process, SIGTERM);
      $status = proc_get_status($process);
      if ($status) {
        if (!$status['running']) {
          fclose($this->pipes[$index][0]);
          fclose($this->pipes[$index][1]);
          proc_close($process);
          unset($this->processes[$index]);
        }
      } else {
        unset($this->processes[$index]);
      }
    }
  }

  /**
   * @return ClientWorker|mixed
   * @throws SocketAcceptUnable
   */
  function accept() {
    if ($socket = socket_accept($this->socket)) {
      return $this->clients[] = new $this->classWorkerClient($socket);
    } else {
      throw new SocketAcceptUnable('Worker');
    }
  }

  function getClientsCount() {
    return count($this->clients);
  }
}