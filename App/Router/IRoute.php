<?php
namespace Hilos\App\Router;

use Exception;

interface IRoute {
  /**
   * @return boolean
   */
  function check(): bool;

  /**
   * @return void
   */
  function follow();

  /**
   * @param Exception $e
   * @return mixed
   */
  function handleFollowException(Exception $e);
}
