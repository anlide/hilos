<?php
namespace Hilos\Deamon;

use Hilos\Deamon\Socket\Connection;
use Hilos\Deamon\Socket\Longpooling;
use Hilos\Deamon\Socket\Websocket;
use Hilos\Deamon\Socket\Worker as SocketWorker;

abstract class Master {
  private static $stopSignal = false;

  private $sockets;

  private $wsInternalPort;
  private $wsAdminPort;
  private $serviceInternalPort;
  private $workerInternalPort;
  private $adminEmail;

  function __construct(
    $wsInternalPort,
    $wsAdminPort,
    $serviceInternalPort,
    $workerInternalPort,
    $adminEmail
  ) {
    $this->wsInternalPort = $wsInternalPort;
    $this->wsAdminPort = $wsAdminPort;
    $this->serviceInternalPort = $serviceInternalPort;
    $this->workerInternalPort = $workerInternalPort;
    $this->adminEmail = $adminEmail;
  }

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

  abstract public function tick();

  public function run() {
    try {
      if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        pcntl_signal(SIGTERM, 'signal_handler');
        pcntl_signal(SIGHUP, 'signal_handler');
      }

      /**
       * @var Connection[] $connections
       */
      $connections = array();
      $sockets = array();
      $this->sockets = &$sockets;
      $master_ws = socket_create(AF_INET, SOCK_STREAM, 0);
      socket_set_option($master_ws, SOL_SOCKET, SO_REUSEADDR, 1);
      socket_bind($master_ws, '127.0.0.1', $this->wsInternalPort);
      socket_listen($master_ws);
      $types['master'] = 'master';
      $master_ws_admin = socket_create(AF_INET, SOCK_STREAM, 0);
      socket_set_option($master_ws_admin, SOL_SOCKET, SO_REUSEADDR, 1);
      socket_bind($master_ws_admin, '127.0.0.1', $this->wsAdminPort);
      socket_listen($master_ws_admin);
      $types['master_admin'] = 'master_admin';
      $master_internal = socket_create(AF_INET, SOCK_STREAM, 0);
      socket_set_option($master_internal, SOL_SOCKET, SO_REUSEADDR, 1);
      socket_bind($master_internal, '127.0.0.1', $this->serviceInternalPort);
      socket_listen($master_internal);
      $types['master_internal'] = 'internal';
      $master_worker = socket_create(AF_INET, SOCK_STREAM, 0);
      socket_set_option($master_worker, SOL_SOCKET, SO_REUSEADDR, 1);
      socket_bind($master_worker, '127.0.0.1', $this->workerInternalPort);
      socket_listen($master_worker);
      $types['master_worker'] = 'master_worker';
      socket_set_nonblock($master_worker);
      socket_set_nonblock($master_internal);
      socket_set_nonblock($master_ws);
      socket_set_nonblock($master_ws_admin);
      $sockets['master'] = $master_ws;
      $sockets['master_admin'] = $master_ws_admin;
      $sockets['master_internal'] = $master_internal;
      $sockets['master_worker'] = $master_worker;

      while (!self::$stopSignal) {
        /**
         * Обработка web socket, внутренних запросов, long polling запросов
         */
        $read = $sockets;
        $write = $except = array();
        if (($num_changed_streams = @socket_select($read, $write, $except, 0, 1000000)) === false) {
          // ВНИМАНИЕ!
          // Тут может быть ошибка связанная с "FD_SETSIZE", типа больше 1024 активных соединений - это борода.
          // Для преодоления этого надо использовать несколько websocket серверов (то есть socket_create).
          // А nginx настроить на roundrobin на локальные порты.
          // Топорненько, но делается быстро - и быстро увеличивает лимит до 100к юзверей онлайн.
          // За это время можно спокойно переписать socket_select на event, libevent, ev или ещё какую-то библиотеку.
          // ЗЫ. Не стоит тратить время на попытки пересобрать php - там бонусных проблем вагон.
          error_log('Hilos core master socket_select error "' . socket_strerror(socket_last_error()) . '"');
          if (socket_strerror(socket_last_error()) != 'Interrupted system call') {
            mail($this->adminEmail, 'Hilos core master socket_select error', socket_strerror(socket_last_error()));
          }
          if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            pcntl_signal_dispatch();
          }
          continue;
        }
        $this->tick();
        foreach ($read as $socket) {
          // Внимание!
          // Это не безопасный код - тут может быть получено сообщение от одного сокета, а система попытается прочесть другой
          // Из-за любой ошибки с коде (на момент написания этого каммента ошибок не было)
          // Надо что-то придумать для гарантированной и очевидной безопасности этого дела
          $index_socket = array_search($socket, $sockets);
          if ($index_socket === false) throw new \Exception('Invalid situation: Not exists socket index');
          $type = $types[$index_socket];
          switch ($type) {
            case 'master':
              // Пришло новое web socket соединение
              if ($socket_new = socket_accept($master_ws)) {
                $sockets[] = $socket_new;
                $index_new_socket = array_search($socket_new, $sockets);
                socket_getpeername($socket_new, $ip);
                $connections[$index_new_socket] = new Websocket($socket_new, $index_new_socket, $ip);
                $index_socket = $index_new_socket;
                $types[$index_socket] = 'ws';
              } else {
                error_log('stream_socket_accept master');
                continue;
              }
              break;
            case 'master_admin':
              // Пришло новое web socket admin соединение админки
              if ($socket_new = socket_accept($master_ws_admin)) {
                $sockets[] = $socket_new;
                $index_new_socket = array_search($socket_new, $sockets);
                socket_getpeername($socket_new, $ip);
                $connections[$index_new_socket] = new Websocket($socket_new, $index_new_socket, $ip, 'admin');
                $index_socket = $index_new_socket;
                $types[$index_socket] = 'admin';
              } else {
                error_log('stream_socket_accept master');
                continue;
              }
              break;
            case 'internal':
              // Обрабатываем новые служебные и long polling соединения
              if ($socket_new = socket_accept($master_internal)) {
                $sockets[] = $socket_new;
                $index_new_socket = array_search($socket_new, $sockets);
                $connections[$index_new_socket] = new Longpooling($socket_new, $index_new_socket);
                $index_socket = $index_new_socket;
                $types[$index_socket] = 'lp';
              } else {
                error_log('stream_socket_accept internal');
                continue;
              }
              break;
            case 'master_worker':
              // Обрабатываем новые worker соединения
              if ($socket_new = socket_accept($master_worker)) {
                $sockets[] = $socket_new;
                $index_new_socket = array_search($socket_new, $sockets);
                $connections[$index_new_socket] = new SocketWorker($socket_new, $index_new_socket);
                $index_socket = $index_new_socket;
                $types[$index_socket] = 'worker';
              } else {
                error_log('stream_socket_accept master_worker');
                continue;
              }
              break;
          }
          $connection = &$connections[$index_socket];
          if ($connection === null) throw new \Exception('Invalid situation: Connection #' . $index_socket . ' is null');
          $connection->on_receive_data();
          $connection->on_read();
          $new_instance = $connection->get_new_instance();
          if ($new_instance !== null) {
            $connections[$index_socket] = clone $new_instance;
            $connection = &$connections[$index_socket];
            $connection->on_read();
          }
          if ($connection->closed()) {
            unset($sockets[$index_socket]);
            unset($connections[$index_socket]);
            unset($connection);
          }
        }
        /**
         * dispatch сигналов из ОСи
         */
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
          pcntl_signal_dispatch();
        }
      }
      foreach ($sockets as $index_socket => $socket) {
        socket_close($socket);
        if (isset($connections[$index_socket])) unset($connections[$index_socket]);
      }
    } catch (\Exception $e) {
      error_log($e->getMessage());
      error_log($e->getTraceAsString());
      print($e->getMessage());
      print($e->getTraceAsString());
      mail($this->adminEmail, 'Hilos core master Exception "'.$e->getMessage().'"', $e->getTraceAsString());
    }
    if (isset($task_manager_master)) $task_manager_master->workers_stop();
    error_log('Safe exit');
  }
}
