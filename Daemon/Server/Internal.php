<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\IClient;
use Hilos\Daemon\Client\Internal as InternalClient;
use Hilos\Daemon\Exception\SocketAcceptUnable;

class Internal extends Server {
  protected $classInternalClient;

  function __construct($port, $classInternalClient = InternalClient::class) {
    $this->port = $port;
    $this->classInternalClient = $classInternalClient;
    $this->autoStart = false;
  }

  /**
   * @return InternalClient|mixed
   * @throws SocketAcceptUnable
   */
  function accept(): IClient {
    if ($socket = socket_accept($this->socket)) {
      return new $this->classInternalClient($socket);
    } else {
      throw new SocketAcceptUnable('Internal');
    }
  }
}