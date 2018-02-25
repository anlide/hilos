<?php
namespace Hilos\Daemon;

use Hilos\Daemon\Client\IClient;
use Hilos\Daemon\Exception\InvalidSituation;
use Hilos\Daemon\Exception\SocketSelect;
use Hilos\Daemon\Server\IServer;

abstract class Master {
  protected static $stopSignal = false;

  protected $adminEmail;

  /** @var IServer[] */
  protected $servers;
  /** @var IClient[] */
  protected $clients;

  protected $sockets;

  public function registerServer(IServer $server) {
    $this->servers[] = $server;
  }

  protected function tick() {}

  public function run() {
    $sockets = [];
    $this->sockets = &$sockets;

    $this->initPcntl();

    try {
      foreach ($this->servers as $index => $server) {
        $sockets['master-'.$index] = $server->start();
      }
      while (!self::$stopSignal) {
        $read = $sockets;
        $write = $except = array();
        if ((@socket_select($read, $write, $except, 0, 1000000)) === false) {

          if (socket_strerror(socket_last_error()) != 'Interrupted system call') {
            if ($this->adminEmail !== null) mail($this->adminEmail, 'Hilos master socket_select error', socket_strerror(socket_last_error()));
            error_log('Hilos master socket_select error "' . socket_strerror(socket_last_error()) . '"');
            throw new SocketSelect(socket_last_error() . '/ ' . socket_strerror(socket_last_error()));
          }
          if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            pcntl_signal_dispatch();
          }
          continue;
        }
        foreach ($read as $socket) {
          $indexSocket = array_search($socket, $sockets);
          if ($indexSocket === false) throw new InvalidSituation('Not exists socket index');
          if (substr($indexSocket, 0, 7) == 'master-') {
            $indexSocketServer = substr($indexSocket, 7);
            $this->acceptNewClient($indexSocketServer);
            end($this->clients);
            $indexSocketClient = key($this->clients);
            $client = &$this->clients[$indexSocketClient];
            $sockets['client-'.$indexSocketClient] = $client->getSocket();
            unset($client);
          }
          if (substr($indexSocket, 0, 7) == 'client-') {
            $indexSocketClient = substr($indexSocket, 7);
            $this->handleClient($indexSocketClient);
            unset($client);
            if ($this->clients[$indexSocketClient]->closed()) {
              $this->closeClient($indexSocketClient);
            }
          }
        }
        $this->tick();
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
          pcntl_signal_dispatch();
        }
      }
      foreach ($this->clients as &$client) {
        $client->stop();
      }
      unset($client);
      foreach ($this->servers as &$server) {
        $server->stop();
      }
      unset($server);
    } catch (\Exception $e) {
      error_log($e->getMessage());
      error_log($e->getTraceAsString());
      print($e->getMessage());
      print($e->getTraceAsString());
      if ($this->adminEmail !== null) {
        mail($this->adminEmail, 'Hilos master Exception "'.$e->getMessage().'"', $e->getTraceAsString());
      }
    }
  }
  protected function acceptNewClient($indexSocketServer) {
    $this->clients[] = $this->servers[$indexSocketServer]->accept();
  }
  protected function handleClient($indexSocketClient) {
    $this->clients[$indexSocketClient]->handle();
  }
  protected function closeClient($indexSocketClient) {
    unset($this->clients[$indexSocketClient]);
  }
  protected function initPcntl() {
    if (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') return;

    function signal_handler($signo) {
      switch ($signo) {
        case SIGTERM:
          error_log('SIGTERM');
          // NOTE: handle stop tasks
          self::$stopSignal = true;
          break;
        case SIGHUP:
          error_log('SIGHUP');
          // NOTE: handle restart tasks
          self::$stopSignal = true;
          break;
        case SIGINT:
          error_log('SIGINT');
          // NOTE: handle exit tasks
          self::$stopSignal = true;
          break;
      }
    }

    pcntl_signal(SIGTERM, 'Hilos\\Daemon\\signal_handler');
    pcntl_signal(SIGHUP, 'Hilos\\Daemon\\signal_handler');
  }
}