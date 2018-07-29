<?php

namespace Hilos\Daemon\Client;

use Hilos\Daemon\Exception\NonJsonResponse;
use Hilos\Daemon\Task\Master as TaskMaster;

abstract class Worker extends Client {
  /** State: first line */
  const STATE_INDEX  = 1;

  /** State: other lines */
  const STATE_WORK  = 2;

  private $indexWorker = null;

  /** @var TaskMaster[] */
  protected $tasks = [];
  protected $delayedSignals = [];

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
  private function readIndex() {
    $line = $this->readLine(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if (!isset($json['index_worker'])) {
      $this->close();
      $this->state = self::CLOSE_PROTOCOL;
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
  private function readWork() {
    $line = $this->readLine(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if ($json === null) {
      error_log('Invalid worker line: "' . $line . '"');
      throw new NonJsonResponse('line read error');
    }
    $this->onReceiveJson($this->indexWorker, $json);
    return true;
  }

  public function taskCount() {
    return count($this->tasks);
  }

  public function taskAdd(TaskMaster &$task) {
    $taskIndex = $task->getTaskIndex();
    $taskIndexString = $task->getTaskIndexString();
    $taskType = $task->getTaskType();
    if ($taskIndex === null) throw new \Exception('taskIndex is null at worker taskAdd');
    if ($taskType === null) throw new \Exception('taskType is null at worker taskAdd');
    if (isset($this->tasks[$taskType . '-' . $taskIndexString])) return false;
    $this->tasks[$taskType . '-' . $taskIndexString] = $task;
    $this->sendSignal('task_add', $taskType, $taskIndex);
    $this->tasks[$taskType . '-' . $taskIndexString]->setCallbackSendToWorker(function($taskType, $taskIndex, $action, $json){
      $this->sendSignal('task_action', $taskType, $taskIndex, $action, $json);
    });
    return true;
  }

  /**
   * @param string $type
   * @param string|int $index
   * @return TaskMaster|null
   */
  public function taskGet($type, $index) {
    if (!$this->taskExists($type, $index)) return null;
    return $this->tasks[$type . '-' . $index];
  }

  /**
   * @param string $type
   * @param string|int $index
   * @return bool
   */
  public function taskExists($type, $index) {
    return isset($this->tasks[$type . '-' . $index]);
  }

  private function sendSignal($workerAction, $taskType, $taskIndex, $action = null, $params = []) {
    $jsonSignal = ['worker_action' => $workerAction, 'task_type' => $taskType, 'task_index' => $taskIndex, 'params' => $params];
    if ($action !== null) {
      $jsonSignal['action'] = $action;
    }
    $signal = json_encode($jsonSignal);
    $this->write($signal.PHP_EOL);
  }

  protected function onConnected($index) {}

  protected function onReceiveJson($indexWorker, $json) {}
}