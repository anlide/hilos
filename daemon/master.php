<?php
namespace Hilos\Daemon;

use Hilos\Daemon\Socket\Connection;
use Hilos\Daemon\Socket\Internal;
use Hilos\Daemon\Socket\Websocket;
use Hilos\Daemon\Socket\Worker as SocketWorker;

abstract class Master {
  const config = [
    'adminEmail' => null,
    'wsInternalPort' => 8001,
    'wsAdminPort' => 8011,
    'serviceInternalPort' => 8005,
    'workerInternalPort' => 8008,
  ];

  private static $stopSignal = false;

  private $sockets;

  private $wsInternalPort;
  private $wsAdminPort;
  private $serviceInternalPort;
  private $workerInternalPort;
  private $adminEmail;

  function __construct($config = []) {
    $config = array_merge(self::config, $config);
    $this->wsInternalPort = $config['wsInternalPort'];
    $this->wsAdminPort = $config['wsAdminPort'];
    $this->serviceInternalPort = $config['serviceInternalPort'];
    $this->workerInternalPort = $config['workerInternalPort'];
    $this->adminEmail = $config['adminEmail'];
  }

  abstract public function tick();

  public function run() {
    try {
      if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
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

      /**
       * @var Connection[] $connections
       */
      $connections = array();
      $sockets = array();
      $this->sockets = &$sockets;
      $masterWs = socket_create(AF_INET, SOCK_STREAM, 0);
      socket_set_option($masterWs, SOL_SOCKET, SO_REUSEADDR, 1);
      socket_bind($masterWs, '127.0.0.1', $this->wsInternalPort);
      socket_listen($masterWs);
      $types['master'] = 'master';
      $masterWsAdmin = socket_create(AF_INET, SOCK_STREAM, 0);
      socket_set_option($masterWsAdmin, SOL_SOCKET, SO_REUSEADDR, 1);
      socket_bind($masterWsAdmin, '127.0.0.1', $this->wsAdminPort);
      socket_listen($masterWsAdmin);
      $types['masterAdmin'] = 'masterAdmin';
      $masterInternal = socket_create(AF_INET, SOCK_STREAM, 0);
      socket_set_option($masterInternal, SOL_SOCKET, SO_REUSEADDR, 1);
      socket_bind($masterInternal, '127.0.0.1', $this->serviceInternalPort);
      socket_listen($masterInternal);
      $types['masterInternal'] = 'masterInternal';
      $masterWorker = socket_create(AF_INET, SOCK_STREAM, 0);
      socket_set_option($masterWorker, SOL_SOCKET, SO_REUSEADDR, 1);
      socket_bind($masterWorker, '127.0.0.1', $this->workerInternalPort);
      socket_listen($masterWorker);
      $types['masterWorker'] = 'masterWorker';
      socket_set_nonblock($masterWorker);
      socket_set_nonblock($masterInternal);
      socket_set_nonblock($masterWs);
      socket_set_nonblock($masterWsAdmin);
      $sockets['master'] = $masterWs;
      $sockets['masterAdmin'] = $masterWsAdmin;
      $sockets['masterInternal'] = $masterInternal;
      $sockets['masterWorker'] = $masterWorker;

      while (!self::$stopSignal) {
        /**
         * Обработка web socket, внутренних запросов, long polling запросов
         */
        $read = $sockets;
        $write = $except = array();
        if ((@socket_select($read, $write, $except, 0, 1000000)) === false) {
          // ВНИМАНИЕ!
          // Тут может быть ошибка связанная с "FD_SETSIZE", типа больше 1024 активных соединений - это борода.
          // Для преодоления этого надо использовать несколько websocket серверов (то есть socket_create).
          // А nginx настроить на roundrobin на локальные порты.
          // Топорненько, но делается быстро - и быстро увеличивает лимит до 100к юзверей онлайн.
          // За это время можно спокойно переписать socket_select на event, libevent, ev или ещё какую-то библиотеку.
          // ЗЫ. Не стоит тратить время на попытки пересобрать php - там бонусных проблем вагон.
          error_log('Hilos core master socket_select error "' . socket_strerror(socket_last_error()) . '"');
          if (socket_strerror(socket_last_error()) != 'Interrupted system call') {
            if ($this->adminEmail !== null) mail($this->adminEmail, 'Hilos core master socket_select error', socket_strerror(socket_last_error()));
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
          $indexSocket = array_search($socket, $sockets);
          if ($indexSocket === false) throw new \Exception('Invalid situation: Not exists socket index');
          $type = $types[$indexSocket];
          switch ($type) {
            case 'master':
              // Пришло новое web socket соединение
              if ($socketNew = socket_accept($masterWs)) {
                $sockets[] = $socketNew;
                $indexNewSocket = array_search($socketNew, $sockets);
                socket_getpeername($socketNew, $ip);
                $connections[$indexNewSocket] = new Websocket($socketNew, $indexNewSocket, $ip);
                $indexSocket = $indexNewSocket;
                $types[$indexSocket] = 'ws';
              } else {
                error_log('stream_socket_accept master');
                continue;
              }
              break;
            case 'masterAdmin':
              // Пришло новое web socket admin соединение админки
              if ($socketNew = socket_accept($masterWsAdmin)) {
                $sockets[] = $socketNew;
                $indexNewSocket = array_search($socketNew, $sockets);
                socket_getpeername($socketNew, $ip);
                $connections[$indexNewSocket] = new Websocket($socketNew, $indexNewSocket, $ip, 'admin');
                $indexSocket = $indexNewSocket;
                $types[$indexSocket] = 'admin';
              } else {
                error_log('stream_socket_accept master');
                continue;
              }
              break;
            case 'masterInternal':
              // Обрабатываем новые служебные и long polling соединения
              if ($socketNew = socket_accept($masterInternal)) {
                $sockets[] = $socketNew;
                $indexNewSocket = array_search($socketNew, $sockets);
                $connections[$indexNewSocket] = new Internal($socketNew, $indexNewSocket);
                $indexSocket = $indexNewSocket;
                $types[$indexSocket] = 'in';
              } else {
                error_log('stream_socket_accept internal');
                continue;
              }
              break;
            case 'masterWorker':
              // Обрабатываем новые worker соединения
              if ($socketNew = socket_accept($masterWorker)) {
                $sockets[] = $socketNew;
                $indexNewSocket = array_search($socketNew, $sockets);
                $connections[$indexNewSocket] = new SocketWorker($socketNew, $indexNewSocket);
                $indexSocket = $indexNewSocket;
                $types[$indexSocket] = 'worker';
              } else {
                error_log('stream_socket_accept master_worker');
                continue;
              }
              break;
          }
          $connection = &$connections[$indexSocket];
          if ($connection === null) throw new \Exception('Invalid situation: Connection #' . $indexSocket . ' is null');
          $connection->onReceiveData();
          $connection->onRead();
          $new_instance = $connection->getNewInstance();
          if ($new_instance !== null) {
            $connections[$indexSocket] = clone $new_instance;
            $connection = &$connections[$indexSocket];
            $connection->onRead();
          }
          if ($connection->closed()) {
            unset($sockets[$indexSocket]);
            unset($connections[$indexSocket]);
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
      foreach ($sockets as $indexSocket => $socket) {
        socket_close($socket);
        if (isset($connections[$indexSocket])) unset($connections[$indexSocket]);
      }
    } catch (\Exception $e) {
      error_log($e->getMessage());
      error_log($e->getTraceAsString());
      print($e->getMessage());
      print($e->getTraceAsString());
      if ($this->adminEmail !== null) mail($this->adminEmail, 'Hilos core master Exception "'.$e->getMessage().'"', $e->getTraceAsString());
    }
    if (isset($task_manager_master)) $task_manager_master->workers_stop();
    error_log('Safe exit');
  }
}
