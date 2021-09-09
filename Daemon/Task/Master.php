<?php

namespace Hilos\Daemon\Task;

/**
 * Class Master
 * @package Hilos\Daemon\Task
 */
abstract class Master implements IMaster {
  private ?string $taskType = null;
  private $taskIndex = null;

  private array $delaySend = [];

  /** @var callable */
  private $callbackSendToWorker = null;

  /**
   * Master constructor.
   * @param string $taskType
   * @param string|int|array|null $taskIndex
   */
  public function __construct(string $taskType, $taskIndex) {
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

  protected function sendToWorker($action, $json = []) {
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

  public function getTaskType(): ?string {
    return $this->taskType;
  }

  public function getTaskIndex() {
    return $this->taskIndex;
  }

  public function getTaskIndexString() {
    return is_array($this->taskIndex) ? implode('-', $this->taskIndex) : $this->taskIndex;
  }
}