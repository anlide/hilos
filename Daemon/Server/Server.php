<?php

namespace Hilos\Daemon\Server;

/**
 * Class Server
 * @package Hilos\Daemon\Server
 */
abstract class Server implements IServer {
  protected int $port;
  /** @var resource */
  protected $socket = null;
  protected bool $autoStart = true;

  function start() {
    if ($this->socket !== null) return null;
    $this->socket = socket_create(AF_INET, SOCK_STREAM, 0);
    socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($this->socket, '::1', $this->port);
    socket_listen($this->socket);
    socket_set_nonblock($this->socket);
    return $this->socket;
  }

  function autoStart() {
    if ($this->autoStart) return $this->start();
    return $this->socket;
  }

  function stop() {
    socket_close($this->socket);
  }

  function tick() {}
}