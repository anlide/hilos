<?php
namespace Hilos\App\Router\Rule;

use Hilos\App\Router\Rule;

/**
 * Class UriPregMatch
 * @package Hilos\App\Router\Rule
 */
class UriPregMatch extends Rule {

  private $preg;

  public function __construct($preg) {
    $this->preg = $preg;
  }

  public function check() {
    $parsed = parse_url($_SERVER['REQUEST_URI']);
    if (!isset($parsed['path'])) return false;
    return preg_match($this->preg, $parsed['path']) == 1;
  }
}
