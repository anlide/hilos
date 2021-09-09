<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\Websocket as WebsocketClient;
use Hilos\Daemon\Exception\SocketAcceptUnable;

class WebSocket extends Server {
  protected $classWebsocketClient;

  function __construct($port, $classWebsocketClient = WebsocketClient::class) {
    $this->port = $port;
    $this->classWebsocketClient = $classWebsocketClient;
    $this->autoStart = false;
  }

  /**
   * @return WebsocketClient|mixed
   * @throws SocketAcceptUnable
   */
  function accept(): WebsocketClient {
    if ($socket = socket_accept($this->socket)) {
      return new $this->classWebsocketClient($socket);
    } else {
      throw new SocketAcceptUnable('WebSocket');
    }
  }
}