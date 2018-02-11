<?php
namespace Hilos\Daemon\Socket;

class Internal extends Connection {
  private $failCount = 0;

  /**
   * Called when new data received.
   * @return boolean|string
   */
  public function onRead() {
    if ($this->closed) return false;
    if (($line = $this->readLine(PHP_EOL)) === null) {
      $this->failCount++;
      if ($this->failCount > 10) {
        $this->close(self::CLOSE_ABNORMAL);
      }
      return false;
    }
    $this->failCount = 0;
    return $line;
  }
}