<?php
namespace Hilos\Tests\App;

use Hilos\App\Kernel as KernelBase;
use Hilos\Tests\App\Route\ExceptionRoute;

class Kernel3 extends KernelBase {
  public function __construct() {
    $this->registerRoute(ExceptionRoute::class);
  }
  public function handleNoRoute() {
    $this->response = 'Wrong request';
  }
}