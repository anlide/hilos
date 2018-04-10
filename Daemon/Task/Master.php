<?php

namespace Hilos\Daemon\Task;

abstract class Master implements IMaster {
  private $taskType = null;
  private $taskIndex = null;

  private $delaySend = [];

  /** @var callable */
  private $callbackSendToWorker = null;

  public function __construct($taskType, $taskIndex) {
    $this->taskType = $taskType;
    $this->taskIndex = $taskIndex;
  }

  public function setCallbackSendToWorker($callback) {
    $this->callbackSendToWorker = $callback;
    foreach ($this->delaySend as $delaySend) {
      $callback($this->taskType, $this->taskIndex, $delaySend['action'], $delaySend['json']);
    }
    $this->delaySend = [];
  }

  protected function sendToWorker($action, $json) {
    if ($this->callbackSendToWorker === null) {
      $this->delaySend[] = [
        'action' => $action,
        'json' => $json,
      ];
    } else {
      $callbackSendToWorker = $this->callbackSendToWorker;
      $callbackSendToWorker($this->taskType, $this->taskIndex, $action, $json);
    }
  }

  public function getTaskType() {
    return $this->taskType;
  }

  public function getTaskIndex() {
    return $this->taskIndex;
  }
}