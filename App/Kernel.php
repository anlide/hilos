<?php
namespace Hilos\App;

use Hilos\App\Exception\RouteInvalid;
use Hilos\App\Router\Route;
use Hilos\App\Exception\NoRouteForRequest;

abstract class Kernel implements IKernel {
  protected $response = '';

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
          ob_start();
          $route->follow();
          $this->response = ob_get_contents();
          ob_end_clean();
        } catch (\Exception $e) {
          ob_end_clean();
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

  public function __toString() {
    return $this->response;
  }
}
