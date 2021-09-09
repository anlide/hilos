<?php

namespace Hilos\Daemon\Worker;

/**
 * Class Master
 * @package Hilos\Daemon\Worker
 */
class Master {
  private string $indexWorker;

  /** @var resource|false */
  private $process;

  public function __construct($indexWorker) {
    $this->indexWorker = $indexWorker;
  }

  /**
   * @param $initialFile
   * @param string $phpExec
   * @throws \Exception
   */
  public function start($initialFile, string $phpExec = 'php') {
    $this->process = popen($phpExec.' '.$initialFile.' --index '.$this->indexWorker, 'r');
    if (!is_resource($this->process)) {
      throw new \Exception('Unable to create worker');
    }
  }

  public function terminate() {
    proc_terminate($this->process, SIGTERM);
  }

  public function getStatus() {
    return proc_get_status($this->process);
  }

  public function stop() {
    proc_close($this->process);
  }
}