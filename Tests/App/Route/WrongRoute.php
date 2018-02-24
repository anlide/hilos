<?php
namespace Hilos\Tests\App\Route;

use Hilos\Daemon\Client;

class WrongRoute {
  public function follow() {
    print 'wrong content';
  }
}