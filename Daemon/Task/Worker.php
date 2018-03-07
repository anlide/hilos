<?php

namespace Hilos\Daemon\Task;

abstract class Worker {
  protected $type;
  protected $index;
  /** @var callable */
  private $writeCallback;
  /** @var callable */
  private $callbackSendToMaster;
  /** @var callable */
  private $callbackSendToTask;

  public function __construct($type, $index) {
    $this->type = $type;
    $this->index = $index;
  }

  public function setWriteCallback($callback) {
    $this->writeCallback = $callback;
  }

  public function setCallbackSendToMaster($callback) {
    $this->callbackSendToMaster = $callback;
  }

  public function setCallbackSendToTask($callback) {
    $this->callbackSendToTask = $callback;
  }

  protected function sendToMaster($action, $json) {
    $callbackSendToMaster = $this->callbackSendToMaster;
    $callbackSendToMaster($this->type, $this->index, $action, $json);
  }

  protected function sendToTask($taskType, $taskIndex, $action, $json) {
    $callbackSendToTask = $this->callbackSendToTask;
    $callbackSendToTask($this->type, $this->index, $taskType, $taskIndex, $action, $json);
  }

  public function send($json) {
    $writeCallback = $this->writeCallback;
    $writeCallback($json);
  }

  public abstract function tick();
  public abstract function onAction($action, $params);
}