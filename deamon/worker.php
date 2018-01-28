<?php
namespace hilos\Deamon;

class Worker {
  public function run() {
    do {
      sleep(1);
    } while (true);
  }
}
