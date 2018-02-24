<?php
namespace Hilos\Tests\App;

use Hilos\App\Kernel as KernelBase;
use Hilos\Tests\App\Route\WrongRoute;

class Kernel2 extends KernelBase {
  public function __construct() {
    $this->registerRoute(WrongRoute::class);
  }
  public function handleNoRoute() {
    $this->response = 'Wrong request';
  }
}