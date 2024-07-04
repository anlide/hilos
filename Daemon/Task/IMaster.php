<?php

namespace Hilos\Daemon\Task;

interface IMaster {
  public function signal($signal, $json);
  public function getClient(): int;
  public function setClient(int $client): void;
  public function isMonopoly(): bool;
}