<?php
namespace Hilos\App\Router;

interface IRoute {
  /**
   * @return boolean
   */
  function check();

  /**
   * @return void
   */
  function follow();

  /**
   * @param \Exception $e
   * @return mixed
   */
  function handleFollowException(\Exception $e);
}
