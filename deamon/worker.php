<?php
namespace hilos;

class Worker {
  public function run() {
    do {
      sleep(1);
    } while (true);
  }
}
