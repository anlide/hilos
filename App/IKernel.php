<?php
namespace Hilos\App;

use Hilos\App\Router\Route;

interface IKernel {
  /**
   * @return void
   */
  function handle();

  /**
   * @return void
   */
  function handleNoRoute();

  /**
   * @param string $routeString
   * @return boolean
   */
  function handleInvalidRoute($routeString);

  /**
   * @param Route $route
   * @param \Exception $e
   * @return void
   */
  function handleFollowException(Route $route, \Exception $e);
}
