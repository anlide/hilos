<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\IClient;
use Hilos\Daemon\Client\Internal as InternalClient;
use Hilos\Daemon\Exception\SocketAcceptUnable;

class Internal extends Server {
  protected $classInternalClient;

  function __construct($port, $host, $classInternalClient = InternalClient::class) {
    $this->port = $port;
    $this->host = $host;
    $this->classInternalClient = $classInternalClient;
    $this->autoStart = false;
  }

  /**
   * @return InternalClient|mixed
   * @throws SocketAcceptUnable
   */
  function accept(): IClient {
    if ($socket = socket_accept($this->socket)) {
      socket_set_nonblock($socket);
      return new $this->classInternalClient($socket);
    } else {
      throw new SocketAcceptUnable('Internal');
    }
  }
}