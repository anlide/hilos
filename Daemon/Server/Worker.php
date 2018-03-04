<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\Worker as ClientWorker;
use Hilos\Daemon\Exception\SocketAcceptUnable;

class Worker extends Server {

  protected $classWorkerClient;
  /** @var resource[] */
  protected $processes = [];
  /** @var ClientWorker[] */
  protected $clients = [];

  function __construct($port, $classWorkerClient = ClientWorker::class) {
    $this->port = $port;
    $this->classWorkerClient = $classWorkerClient;
  }

  public function runWorkers($initialFile, $count) {
    for ($index = 0; $index < $count; $index++) {
      $this->processes[$index] = popen('php '.$initialFile.' --index ' . $index, 'r');
      if (!is_resource($this->processes[$index])) {
        throw new \Exception('unable to create worker');
      }
    }
  }

  public function addTask($task) {
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
    $this->clients[$minIndex]->taskAdd($task);
  }

  function stop() {
    parent::stop();
    foreach ($this->processes as $index => $process) {
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