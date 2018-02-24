<?php
namespace Hilos\Tests\Daemon;

use Hilos\Daemon\Master as BaseMaster;

class Master1 extends BaseMaster {
  protected function closeClient($indexSocketClient) {
    parent::closeClient($indexSocketClient);
    self::$stopSignal = true;
  }
}