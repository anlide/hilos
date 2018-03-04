<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\Worker as WorkerClient;
use Hilos\Daemon\Exception\SocketAcceptUnable;

class Worker extends Server {
  protected $classWorkerClient;

  function __construct($port, $classWorkerClient = WorkerClient::class) {
    $this->port = $port;
    $this->classWorkerClient = $classWorkerClient;
  }

  function accept() {
    if ($socket = socket_accept($this->socket)) {
      return new $this->classWorkerClient($socket);
    } else {
      throw new SocketAcceptUnable('Worker');
    }
  }
}