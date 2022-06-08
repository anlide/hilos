<?php

namespace Hilos\Daemon\Task;

interface IMaster {
  public function signal($signal, $json);
  public function isMonopoly(): bool;
}