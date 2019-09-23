<?php

namespace Hilos\Daemon\Worker;

/**
 * Class Master
 * @package Hilos\Daemon\Worker
 */
class Master {
  private $indexWorker;

  private $pipes;
  private $process;

  public function __construct($indexWorker) {
    $this->indexWorker = $indexWorker;
  }

  /**
   * @param $initialFile
   * @param string $phpExec
   * @throws \Exception
   */
  public function start($initialFile, $phpExec = 'php') {
    $this->process = popen($phpExec.' '.$initialFile.' --index '.$this->indexWorker, 'r');
    if (!is_resource($this->process)) {
      throw new \Exception('unable to create worker');
    }
  }

  public function terminate() {
    proc_terminate($this->process, SIGTERM);
  }

  public function getStatus() {
    return proc_get_status($this->process);
  }

  public function stop() {
    fclose($this->pipes[0]);
    fclose($this->pipes[1]);
    proc_close($this->process);
  }
}