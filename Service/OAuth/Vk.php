<?php
namespace Hilos\Service\OAuth;

use Exception;
use Hilos\Daemon\Exception\TooManyRequestsPerSeconds;
use Hilos\Service\Exception\OAuth\AccessTokenEmpty;
use Hilos\Service\Exception\OAuth\AccessTokenExpired;
use Hilos\Service\Exception\OAuth\VkNoId;
use Hilos\Service\OAuth;

/**
 * Class Vk
 * @package Hilos\Service\OAuth
 */
class Vk extends OAuth {
  function getRedirectUrl(): string {
    return $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['SERVER_NAME'].'/oauth/method=vk';
  }

  function getUrl(): string {
    $redirect_url = urlencode($this->getRedirectUrl());
    return 'https://oauth.vk.com/authorize?client_id='.$this->appId.'&response_type=code&display=page&v=5.8&redirect_uri='.$redirect_url;
  }

  /**
   * @param $code
   * @throws AccessTokenEmpty
   * @throws AccessTokenExpired
   * @throws TooManyRequestsPerSeconds
   * @throws VkNoId
   */
  function fetchUserData($code) {
    $authTokenUrl = 'https://oauth.vk.com/access_token?client_id='.$this->appId.'&client_secret='.$this->secret.'&redirect_uri='.$this->getRedirectUrl().'&code='.$code;
    $resp = file_get_contents($authTokenUrl);
    $data = json_decode($resp, true);
    if (!isset($data['access_token'])) {
      throw new AccessTokenEmpty();
    }
    $this->accessToken = $data['access_token'];
    $this->fetchByToken($data['access_token'], $data['user_id']);
  }

  /**
   * @param $accessToken
   * @param null $params
   * @throws AccessTokenExpired
   * @throws TooManyRequestsPerSeconds
   * @throws VkNoId
   * @throws Exception
   */
  function fetchByToken($accessToken, $params = null) {
    $paramsQuery = array(
      'uids'         => $params,
      'fields'       => 'uid,first_name,last_name,sex,photo_big,country,city',
      'access_token' => $accessToken,
      'v' => '5.8'
    );
    $userInfo = json_decode(file_get_contents('https://api.vk.com/method/users.get?'.urldecode(http_build_query($paramsQuery))), true);
    if (isset($userInfo['error'])) {
      if (!isset($userInfo['error']['error_code'])) {
        throw new Exception('Vk return wrong error format: '.json_encode($userInfo['error']));
      }
      switch ($userInfo['error']['error_code']) {
        case 5:
          throw new AccessTokenExpired();
        case 6:
          throw new TooManyRequestsPerSeconds();
        default:
          throw new Exception('Vk auth unknown error: '.json_encode($userInfo['error']));
      }
    }
    $this->parseData($userInfo);
  }

  /**
   * @param $userInfo
   * @param null $params
   * @throws VkNoId
   */
  function parseData($userInfo, $params = null) {
    $this->provider = 'vk';
    if (isset($userInfo['response'][0]['id'])) {
      $this->providerKey = $userInfo['response'][0]['id'];
    } elseif (isset($userInfo['response'][0]['uid'])) {
      $this->providerKey = $userInfo['response'][0]['uid'];
    } else {
      throw new VkNoId();
    }
    if (isset($userInfo['response'][0]['first_name'])) {
      $this->user = $userInfo['response'][0]['first_name'];
      if (isset($userInfo['response'][0]['last_name'])) {
        $this->user .= ' '.$userInfo['response'][0]['last_name'];
      }
    }
    if (isset($userInfo['response'][0]['photo_big'])) {
      $this->userPic = $userInfo['response'][0]['photo_big'];
      if (substr($this->userPic, 0, 5) != 'https') $this->userPic = null; // TODO: Закачивать картинку себе и использовать её с локального сайта
    }
    $this->sex = ($userInfo['response'][0]['sex'] == 2);
  }
}