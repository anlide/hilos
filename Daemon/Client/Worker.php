<?php

namespace Hilos\Daemon\Client;

use Exception;
use Hilos\Daemon\Exception\NonJsonResponse;
use Hilos\Daemon\Task\IMaster;
use Hilos\Daemon\Task\Master as TaskMaster;

abstract class Worker extends Client {
  /** State: first line */
  const STATE_INDEX  = 1;

  /** State: other lines */
  const STATE_WORK  = 2;

  /** State: crashed */
  const STATE_CRASHED  = 3;

  private $indexWorker = null;

  /** @var TaskMaster[] */
  protected array $tasks = [];
  protected array $delayedSignals = [];

  /** @var null|bool */
  private ?bool $monopolyStatus = null;

  function __construct($socket) {
    $this->socket = $socket;
  }

  public function getIndexWorker() {
    return $this->indexWorker;
  }

  /**
   * @throws NonJsonResponse
   */
  function handle() {
    if ($this->closed) return;
    $this->receiveData();
    if ($this->state === self::STATE_STANDBY) {
      $this->state = self::STATE_INDEX;
    }
    if ($this->state === self::STATE_INDEX) {
      if (!$this->readIndex()) {
        return;
      }
      $this->state = self::STATE_WORK;
    }
    if ($this->state === self::STATE_WORK) {
      while ($this->readWork());
    }
  }

  /**
   * @return bool
   */
  private function readIndex(): bool {
    $line = $this->readLine(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if (!isset($json['index_worker'])) {
      error_log('DEBUG: First message from work is without "index_worker": '.$line);
      $this->close(self::CLOSE_PROTOCOL);
      $this->state = self::STATE_CRASHED;
      return false;
    }
    $this->indexWorker = intval($json['index_worker']);
    $this->onConnected($this->indexWorker);
    return true;
  }

  /**
   * @return bool
   * @throws NonJsonResponse
   */
  private function readWork(): bool {
    $line = $this->readLine(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if ($json === null) {
      error_log('DEBUG: Invalid worker line: "' . $line . '"');
      throw new NonJsonResponse('line read error');
    }
    $this->onReceiveJson($this->indexWorker, $json);
    return true;
  }

  public function taskCount(): int {
    return count($this->tasks);
  }

  /**
   * @param IMaster $task
   * @return bool
   * @throws Exception
   */
  public function taskAdd(IMaster &$task): bool {
    $taskIndex = $task->getTaskIndex();
    $taskIndexString = $task->getTaskIndexString();
    $taskType = $task->getTaskType();
    if ($taskIndex === null) throw new Exception('taskIndex is null at worker taskAdd');
    if ($taskType === null) throw new Exception('taskType is null at worker taskAdd');
    if (isset($this->tasks[$taskType . '-' . $taskIndexString])) return false;
    $this->tasks[$taskType . '-' . $taskIndexString] = $task;
    $this->sendSignal('task_add', $taskType, $taskIndex);
    $this->tasks[$taskType . '-' . $taskIndexString]->setCallbackSendToWorker(function($taskType, $taskIndex, $action, $json, $priority){
      $this->sendSignal('task_action', $taskType, $taskIndex, $action, $json, $priority);
    });

    return true;
  }

  /**
   * @param string $type
   * @param string|int $index
   * @return TaskMaster|null
   */
  public function taskGet(string $type, $index): ?TaskMaster {
    if (!$this->taskExists($type, $index)) return null;
    return $this->tasks[$type . '-' . $index];
  }

  /**
   * @param string $type
   * @param string|int $index
   * @return bool
   */
  public function taskExists(string $type, $index): bool {
    return isset($this->tasks[$type . '-' . $index]);
  }

  /**
   * @param $action
   * @param $params
   * @return void
   * @throws Exception
   */
  public function systemSignal($taskType, $taskIndex, $action = null, $params = [])
  {
    $this->sendSignal('task_system', $taskType, $taskIndex, $action, $params, 9);
  }

  /**
   * @throws Exception
   */
  protected function sendSignal($workerAction, $taskType, $taskIndex, $action = null, $params = [], $priority = 0) {
    $jsonSignal = ['worker_action' => $workerAction, 'task_type' => $taskType, 'task_index' => $taskIndex];
    if ($action !== null) {
      $jsonSignal['action'] = $action;
    }
    if ($params !== []) {
      $jsonSignal['params'] = $params;
    }
    if ($priority !== 0) {
      $jsonSignal['priority'] = $priority;
    }
    $signal = json_encode($jsonSignal);
    $this->write($signal.PHP_EOL);
  }

  /**
   * @param bool|null $monopolyStatus
   */
  public function setMonopolyStatus(?bool $monopolyStatus) {
    $this->monopolyStatus = $monopolyStatus;
  }

  /**
   * @return bool|null
   */
  public function getMonopolyStatus(): ?bool {
    return $this->monopolyStatus;
  }

  protected function onConnected($index) {}

  protected function onReceiveJson($indexWorker, $json) {}
}