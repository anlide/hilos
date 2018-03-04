<?php

namespace Hilos\Daemon\Client;

abstract class Internal extends Client {
  private $failCount = 0;

  function __construct($socket) {
    $this->socket = $socket;
  }

  function handle() {
    if ($this->closed) return;
    $this->receiveData();
    if (($line = $this->readLine(PHP_EOL)) === null) {
      $this->failCount++;
      if ($this->failCount > 10) {
        $this->close(self::CLOSE_ABNORMAL);
      }
      return;
    }
    $this->failCount = 0;
    $this->onReceiveLine($line);
  }

  /**
   * This method should be overrided and used.
   *
   * @param $line
   */
  public abstract function onReceiveLine($line);
}