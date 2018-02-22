<?php

namespace Hilos\Daemon\Server;

class Worker extends Server {
  function __construct($port) {
    $this->port = $port;
  }

  function accept() {
    // TODO: Implement accept() method.
  }
}