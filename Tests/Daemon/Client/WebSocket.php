<?php

namespace Hilos\Tests\Daemon\Client;

use Hilos\Daemon\Client\WebSocket as WebSocketBase;
use Hilos\Tests\Daemon\Exception\WebSocketUnknownAction;

class WebSocket extends WebSocketBase {
  /**
   * @param $data
   * @param $type
   * @throws WebSocketUnknownAction
   */
  public function onFrame($data, $type) {
    $json = json_decode($data, true);
    if (isset($json['action'])) {
      $action = $json['action'];
      unset($json['action']);
      $data = $json;
    } else {
      $action = null;
      $data = null;
    }
    try {
      $this->switcher($action, $data);
    } catch (WebSocketUnknownAction $e) {
      throw new $e;
    }
  }

  /**
   * @param $action
   * @param $data
   * @throws WebSocketUnknownAction
   */
  private function switcher($action, $data) {
    switch ($action) {
      case 'test':
        $this->actionTest($data);
        break;
      default:
        throw new WebSocketUnknownAction($action);
        break;
    }
  }

  private function actionTest($data) {
    $this->protocol->sendFrame([
      'action' => 'test',
      'data' => $data,
    ]);
  }
}