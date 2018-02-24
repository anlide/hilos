<?php
namespace Hilos\Tests\App\Route;

use Hilos\App\Router\Route;
use Hilos\App\Router\Rule\UriEqual;
use Hilos\Daemon\Client;
use Hilos\Tests\App\Exception\Custom;

class ExceptionRoute extends Route {
  public function __construct() {
    $this->addRule(new UriEqual('/exception'));
  }
  public function follow() {
    throw new Custom();
  }
}