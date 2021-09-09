<?php
namespace Hilos\Service;

abstract class OAuth implements IOAuth {
  public ?string $provider = null;
  public ?string $providerKey = null;
  public ?string $user = null;
  public ?string $userPic = null;
  public ?bool $sex = null;

  protected string $appId;
  protected string $secret;
  protected ?string $accessToken = null;

  protected static array $instances = array();

  private function __clone() {}
  protected function __construct() {}

  /**
   * @param $provider
   * @return OAuth
   */
  public static function getInstance($provider): OAuth {
    if (!isset(self::$instances[$provider])) {
      $class = 'Hilos\\Service\\OAuth\\'.ucwords($provider);
      self::$instances[$provider] = new $class();
    }

    return self::$instances[$provider];
  }

  public function getAccessToken(): ?string {
    return $this->accessToken;
  }

  public function toArray(): array {
    return [
      'provider' => $this->provider,
      'providerKey' => $this->providerKey,
      'user' => $this->user,
      'userPic' => $this->userPic,
      'sex' => $this->sex,
    ];
  }
}