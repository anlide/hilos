<?php
namespace Hilos\Deamon\Socket;

class Longpooling extends Connection {
  private $fail_count = 0;

  /**
   * Called when new data received.
   * @return void
   */
  public function on_read() {
    if ($this->closed) return;
    if (($line = $this->read_line(PHP_EOL)) === null) {
      $this->fail_count++;
      if ($this->fail_count > 10) {
        $this->close(self::CLOSE_ABNORMAL);
      }
      return null;
    }
    $this->fail_count = 0;
    $task_manager_master = hilos_task_manager_master::get_instance();
    $task_manager_master->internal_message_process($this, json_decode($line, true));
  }
}