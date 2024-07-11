<?php

namespace Hilos\Daemon\Task;

/**
 * Class Worker
 * @package Hilos\Daemon\Task
 */
abstract class Worker {
  protected $type;
  protected $index;

  /** @var callable */
  private $writeCallback;

  /** @var callable */
  private $callbackSendToMaster;

  /** @var callable */
  private $callbackSendToTask;

  /** @var callable */
  private $callbackSelfStop;

  /** @var callable */
  private $callbackBroadcastAllWorkers;

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

  public function setCallbackSelfStop($callback) {
    $this->callbackSelfStop = $callback;
  }

  public function setCallbackBroadcastAllWorkers($callback)
  {
    $this->callbackBroadcastAllWorkers = $callback;
  }

  protected function sendToMaster($action, $json = []) {
    $callbackSendToMaster = $this->callbackSendToMaster;
    $callbackSendToMaster($action, $json);
  }

  protected function sendToTask($taskType, $taskIndex, $action, $json = []) {
    $callbackSendToTask = $this->callbackSendToTask;
    $callbackSendToTask($taskType, $taskIndex, $action, $json);
  }

  public function send($json) {
    $writeCallback = $this->writeCallback;
    $writeCallback($json);
  }

  protected function selfStop() {
    $callbackSelfStop = $this->callbackSelfStop;
    $callbackSelfStop();
  }

  protected function broadcastAllworkers($action, $json)
  {
    $callbackBroadcastAllWorkers = $this->callbackBroadcastAllWorkers;
    $callbackBroadcastAllWorkers($action, $json);
  }

  public function isMonopoly(): bool
  {
    return false;
  }

  public abstract function tick();
  public abstract function onAction($action, $params);
  public abstract function callbacksInitiated();
}