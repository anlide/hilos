<?php
namespace Hilos\Tests\App\Route;

use Hilos\App\Router\Route;
use Hilos\App\Router\Rule\UriEqual;
use Hilos\Daemon\Client;

class TestRoute extends Route {
  public function __construct() {
    $this->addRule(new UriEqual('/test'));
  }
  public function follow() {
    print 'content';
  }
}