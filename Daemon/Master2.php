<?php
namespace Hilos\Daemon;

use Hilos\Daemon\Client\IClient;
use Hilos\Daemon\Server\IServer;

abstract class Master2 {
  private static $stopSignal = false;

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
            throw new \Exception('socket_last_error['.socket_last_error().']: '.socket_strerror(socket_last_error()));
          }
          if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            pcntl_signal_dispatch();
          }
          continue;
        }
        foreach ($read as $socket) {
          $indexSocket = array_search($socket, $sockets);
          if ($indexSocket === false) throw new \Exception('Invalid situation: Not exists socket index');
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
}