<?php

namespace Hilos\Daemon\Server;

use Hilos\Daemon\Client\IClient;

interface IServer {
  /**
   * @return mixed Socket Connection Resource
   */
  public function start();

  /**
   * @return mixed Socket Connection Resource
   */
  public function autoStart();

  /**
   * @return IClient
   */
  public function accept();

  public function stop();

  public function tick();
}