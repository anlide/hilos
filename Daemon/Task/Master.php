<?php

namespace Hilos\Daemon\Task;

abstract class Master {
  private $taskType = null;
  private $taskIndex = null;

  public function __construct($taskType, $taskIndex) {
    $this->taskType = $taskType;
    $this->taskIndex = $taskIndex;
  }

  public function getTaskType() {
    return $this->taskType;
  }

  public function getTaskIndex() {
    return $this->taskIndex;
  }
}