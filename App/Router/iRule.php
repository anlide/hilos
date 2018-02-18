<?php
namespace Hilos\App\Router;

interface IRule {
  /**
   * @return boolean
   */
  function check();
}
