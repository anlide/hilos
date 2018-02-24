<?php
namespace Hilos\Tests\App;

use Hilos\App\Kernel as KernelBase;
use Hilos\Tests\App\Route\TestMatchRoute;
use Hilos\Tests\App\Route\TestRoute;

class Kernel1 extends KernelBase {
  public function __construct() {
    $this->registerRoute(TestRoute::class);
    $this->registerRoute(TestMatchRoute::class);
  }
}