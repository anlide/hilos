<?php
namespace Hilos\App\Router;

use Exception;

abstract class Route implements IRoute {
  /** @var IRule[] */
  protected array $rules = [];

  public abstract function __construct();

  protected function addRule(IRule $rule) {
    $this->rules[] = $rule;
  }

    /**
     * @return bool
     */
  public function check(): bool {
    foreach ($this->rules as $rule) {
      if (!$rule->check()) {
        return false;
      }
    }

    return true;
  }

  /**
   * @param Exception $e
   * @return void
   * @throws Exception
   */
  public function handleFollowException(Exception $e) {
    throw $e;
  }
}
