<?php
namespace Hilos\Tests\App\Route;

use Hilos\App\Router\Route;
use Hilos\App\Router\Rule\UriPregMatch;
use Hilos\Daemon\Client;

class TestMatchRoute extends Route {
  public function __construct() {
    $this->addRule(new UriPregMatch('~^/test=\w+$~'));
  }
  public function follow() {
    print 'content-match';
  }
}