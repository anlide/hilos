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
   * @var null|array
   */
  protected $pipes = null;

  function __construct($port, $classWorkerClient = ClientWorker::class) {
    $this->port = $port;
    $this->classWorkerClient = $classWorkerClient;
  }

  public function runWorkers($initialFile, $count) {
    for ($index = 0; $index < $count; $index++) {
      $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["file", 'daemon-worker.' . $index . '.error.log', "a"]
      ];
      $this->processes[$index] = proc_open(Config::env('HILOS_DAEMON_LOG_FILE') . ' ' . $initialFile . ' --index ' . $index, $descriptorspec, $this->pipes, dirname($initialFile));
      if (!is_resource($this->processes[$index])) {
        throw new \Exception('unable to create worker');
      }
    }
  }

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
      fclose($this->pipes[0]);
      fclose($this->pipes[1]);
      proc_close($process);
    }
  }

  function accept() {
    if ($socket = socket_accept($this->socket)) {
      return $this->clients[] = new $this->classWorkerClient($socket);
    } else {
      throw new SocketAcceptUnable('Worker');
    }
  }
}