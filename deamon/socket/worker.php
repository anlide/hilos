<?php
namespace Hilos\Deamon\Socket;

class Worker extends Connection {
  /**
   * State: first line
   */
  const STATE_INDEX  = 1;

  /**
   * State: first line
   */
  const STATE_WORK  = 2;

  private $index = null;

  public function on_read() {
    if ($this->closed) return;
    if ($this->state === self::STATE_STANDBY) {
      $this->state = self::STATE_INDEX;
    }
    if ($this->state === self::STATE_INDEX) {
      if (!$this->read_index()) {
        return;
      }
      $this->state = self::STATE_WORK;
    }
    if ($this->state === self::STATE_WORK) {
      while ($this->read_work());
    }
  }

  private function read_index() {
    $line = $this->read_line(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if (!isset($json['index_worker'])) {
      $this->close();
      $this->state = self::STATE_STANDBY;
      return false;
    }
    $this->index = intval($json['index_worker']);
    $task_manager_master = hilos_task_manager_master::get_instance();
    $task_manager_master->worker_connected($this->socket, $this->index);
    return true;
  }

  private function read_work() {
    $line = $this->read_line(PHP_EOL);
    if ($line === null) return false;
    $json = json_decode($line, true);
    if ($json === null) {
      error_log('Invalid worker line: "'.$line.'"');
      throw new \Exception('line read error');
    }
    $task_manager_master = hilos_task_manager_master::get_instance();
    $task_manager_master->worker_clean_receive($this->index, $json);
    return true;
  }
}