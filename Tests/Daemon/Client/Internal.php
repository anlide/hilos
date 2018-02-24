<?php

namespace Hilos\Tests\Daemon\Client;

use Hilos\Daemon\Client\Internal as InternalBase;
use Hilos\Tests\Daemon\Exception\InternalNonJsonRequest;
use Hilos\Tests\Daemon\Exception\InternalUnknownMethod;
use Hilos\Tests\Daemon\Exception\InternalWrongParams;

class Internal extends InternalBase {
  /**
   * @param $line
   * @throws InternalNonJsonRequest
   * @throws InternalUnknownMethod
   * @throws InternalWrongParams
   */
  public function onReceiveLine($line) {
    $json = json_decode($line, true);
    if ($json !== null) {
      if ((isset($json['method'])) && (isset($json['data']))) {
        $this->switcher($json['method'], $json['data']);
      } else {
        throw new InternalWrongParams($line);
      }
    } else {
      throw new InternalNonJsonRequest($line);
    }
  }

  /**
   * @param $method
   * @param $data
   * @throws InternalUnknownMethod
   */
  private function switcher($method, $data) {
    switch ($method) {
      case 'test':
        $this->write(json_encode([
          'method' => $method,
          'data' => $data,
        ]));
        $this->close(self::CLOSE_NORMAL);
        break;
      default:
        throw new InternalUnknownMethod($method);
        break;
    }
  }
}