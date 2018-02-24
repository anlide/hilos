<?php
namespace Hilos\App\Router;

abstract class Route implements IRoute {
  /** @var IRule[] */
  protected $rules = [];
  public abstract function __construct();
  protected function addRule(IRule $rule) {
    $this->rules[] = $rule;
  }
  public function check() {
    foreach ($this->rules as $rule) {
      if (!$rule->check()) {
        return false;
      }
    }
    return true;
  }
  public function handleFollowException(\Exception $e) {
    throw $e;
  }
}
