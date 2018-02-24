<?php
namespace Hilos\Service;

abstract class OAuth implements IOAuth {
  public $provider = null;
  public $providerKey = null;
  public $user = null;
  public $userpic = null;
  public $sex = null;

  protected $appId;
  protected $secret;
  protected $accessToken = null;

  private static $instances = array();

  private function __clone() {}
  protected function __construct() {}

  /**
   * @param $provider
   * @return OAuth
   */
  public static function getInstance($provider) {
    if (!isset(self::$instances[$provider])) {
      $class = 'Hilos\\Service\\OAuth\\'.ucwords($provider);
      self::$instances[$provider] = new $class();
    }
    return self::$instances[$provider];
  }

  public function getAccessToken() {
    return $this->accessToken;
  }

  public function toArray() {
    return [
      'provider' => $this->provider,
      'providerKey' => $this->providerKey,
      'user' => $this->user,
      'userpic' => $this->userpic,
      'sex' => $this->sex,
    ];
  }
}