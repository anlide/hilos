<?php
namespace Hilos\Deamon\Socket;

class Worker extends Connection {
  /**
   * State: first line
   */
  const STATE_INDEX  = 1;

  /**
   * State: first line
   */
  const STATE_WORK  = 2;

  protected $index = null;

  public function onRead() {
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

  protected function readIndex() {
    $line = $this->readLine(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if (!isset($json['index_worker'])) {
      $this->close();
      $this->state = self::STATE_STANDBY;
      return false;
    }
    $this->index = intval($json['index_worker']);
    return true;
  }

  protected function readWork() {
    $line = $this->readLine(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if ($json === null) {
      error_log('Invalid worker line: "'.$line.'"');
      throw new \Exception('line read error');
    }
    return $json;
  }
}