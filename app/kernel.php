<?php
namespace Hilos\App;

use Hilos\App\Router\Route;

class Kernel implements IKernel {
  private $routeStrings = [];

  protected function registerRoute(string $routeString) {
    $this->routeStrings[] = $routeString;
  }

  public function handle() {
    foreach ($this->routeStrings as $routeString) {
      $route = new $routeString();
      if (!($route instanceof Route)) {
        if (!$this->handleInvalidRoute($routeString)) {
          return;
        }
      }
      if ($route->check()) {
        try {
          $route->follow();
        } catch (\Exception $e) {
          $this->handleFollowException($route, $e);
        }
        return;
      }
    }
    $this->handleNoRoute();
  }

  public function handleInvalidRoute($routeString) {
    throw new \Exception('Provided route [' . $routeString . '] is not instance of a Hilos\App\Router\Route');
  }

  public function handleNoRoute() {
    throw new \Exception('No routes for request');
  }

  public function handleFollowException(Route $route, \Exception $e) {
    $route->handleFollowException($e);
  }
}
