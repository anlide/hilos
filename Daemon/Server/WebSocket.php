<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\IClient;
use Hilos\Daemon\Client\Websocket as WebsocketClient;
use Hilos\Daemon\Exception\SocketAcceptUnable;

class WebSocket extends Server {
  protected $classWebsocketClient;

  function __construct($port, $host, $classWebsocketClient = WebsocketClient::class) {
    $this->port = $port;
    $this->host = $host;
    $this->classWebsocketClient = $classWebsocketClient;
    $this->autoStart = false;
  }

  /**
   * @return WebsocketClient|mixed
   * @throws SocketAcceptUnable
   */
  function accept(): IClient {
    if ($socket = socket_accept($this->socket)) {
      socket_set_nonblock($socket);
      return new $this->classWebsocketClient($socket);
    } else {
      throw new SocketAcceptUnable('WebSocket');
    }
  }
}