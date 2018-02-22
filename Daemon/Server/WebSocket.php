<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\Websocket as WebsocketClient;

class WebSocket extends Server {
  protected $classWebsocketClient;

  function __construct($port, $classWebsocketClient = WebsocketClient::class) {
    $this->port = $port;
    $this->classWebsocketClient = $classWebsocketClient;
  }

  function accept() {
    if ($socket = socket_accept($this->socket)) {
      return new $this->classWebsocketClient($socket);
    } else {
      throw new \Exception('Unable to accept socket');
    }
  }

  function stop() {
    // TODO: Implement stop() method.
  }
}