<?php
namespace Hilos\Daemon;

class Worker {
  public function run() {
    do {
      sleep(1);
    } while (true);
  }
}
