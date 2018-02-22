<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\Internal as InternalClient;

class Internal extends Server {
  protected $classInternalClient;

  function __construct($port, $classInternalClient = InternalClient::class) {
    $this->port = $port;
    $this->classInternalClient = $classInternalClient;
  }

  function accept() {
    if ($socket = socket_accept($this->socket)) {
      return new $this->classInternalClient($socket);
    } else {
      throw new \Exception('Unable to accept socket');
    }
  }

  function stop() {
    // TODO: Implement stop() method.
  }
}