<?php

namespace Hilos\Daemon\Client;

use Hilos\Daemon\Exception\NonJsonResponse;

abstract class Worker extends Client {
  /**
   * State: first line
   */
  const STATE_INDEX  = 1;

  /**
   * State: other lines
   */
  const STATE_WORK  = 2;

  private $index = null;

  function __construct($socket) {
    $this->socket = $socket;
  }

  /**
   * @throws NonJsonResponse
   */
  function handle() {
    if ($this->closed) return;
    if ($this->state === self::STATE_STANDBY) {
      $this->state = self::STATE_INDEX;
    }
    if ($this->state === self::STATE_INDEX) {
      if (!$this->readIndex()) {
        return;
      }
      $this->state = self::STATE_WORK;
    }
    if ($this->state === self::STATE_WORK) {
      while ($this->readWork());
    }
  }

  /**
   * @return bool
   */
  private function readIndex() {
    $line = $this->readLine(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if (!isset($json['index_worker'])) {
      $this->close();
      $this->state = self::CLOSE_PROTOCOL;
      return false;
    }
    $this->index = intval($json['index_worker']);
    $this->onConnected($this->index);
    return true;
  }

  /**
   * @return bool
   * @throws NonJsonResponse
   */
  private function readWork() {
    $line = $this->readLine(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if ($json === null) {
      error_log('Invalid worker line: "' . $line . '"');
      throw new NonJsonResponse('line read error');
    }
    $this->onReceiveJson($this->index, $json);
    return true;
  }

  public abstract function onConnected($index);

  public abstract function onReceiveJson($index, $json);
}