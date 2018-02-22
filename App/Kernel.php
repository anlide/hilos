<?php
namespace Hilos\App;

use Hilos\App\Exception\RouteInvalid;
use Hilos\App\Router\Route;
use Hilos\Daemon\Exception\NoRouteForRequest;

class Kernel implements IKernel {
  private $routeStrings = [];

  protected function registerRoute(string $routeString) {
    $this->routeStrings[] = $routeString;
  }

  /**
   * @throws NoRouteForRequest
   * @throws RouteInvalid
   */
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

  /**
   * @param string $routeString
   * @throws RouteInvalid
   * @return boolean
   */
  public function handleInvalidRoute($routeString) {
    throw new RouteInvalid($routeString);
  }

  /**
   * @throws NoRouteForRequest
   */
  public function handleNoRoute() {
    throw new NoRouteForRequest();
  }

  public function handleFollowException(Route $route, \Exception $e) {
    $route->handleFollowException($e);
  }
}
