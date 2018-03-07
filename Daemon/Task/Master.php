<?php

namespace Hilos\Daemon\Task;

abstract class Master implements IMaster {
  private $taskType = null;
  private $taskIndex = null;

  /** @var callable */
  private $callbackSendToWorker;

  public function __construct($taskType, $taskIndex) {
    $this->taskType = $taskType;
    $this->taskIndex = $taskIndex;
  }

  public function setCallbackSendToWorker($callback) {
    $this->callbackSendToWorker = $callback;
  }

  protected function sendToWorker($action, $json) {
    $callbackSendToWorker = $this->callbackSendToWorker;
    $callbackSendToWorker($this->taskType, $this->taskIndex, $action, $json);
  }

  public function getTaskType() {
    return $this->taskType;
  }

  public function getTaskIndex() {
    return $this->taskIndex;
  }
}