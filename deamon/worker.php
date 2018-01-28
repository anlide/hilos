<?php
namespace Hilos\Deamon;

class Worker {
  public function run() {
    do {
      sleep(1);
    } while (true);
  }
}
