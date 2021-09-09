<?php
namespace Hilos\App\Router\Rule;

use Hilos\App\Router\Rule;

/**
 * Class UriEqual
 * @package Hilos\App\Router\Rule
 */
class UriEqual extends Rule {

  private string $uri;

  public function __construct($uri) {
    $this->uri = $uri;
  }

  public function check(): bool {
    return ($_SERVER['REQUEST_URI'] == $this->uri);
  }
}
