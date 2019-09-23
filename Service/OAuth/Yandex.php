<?php
namespace Hilos\Service\OAuth;

use Hilos\Service\Exception\OAuth\AccessTokenEmpty;
use Hilos\Service\OAuth;

/**
 * Class Yandex
 * @package Hilos\Service\OAuth
 */
class Yandex extends OAuth {
  function getRedirectUrl() {
    return $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['SERVER_NAME'].'/oauth/method=yandex';
  }

  function getUrl() {
    $redirect_url = urlencode($this->getRedirectUrl());
    return 'https://oauth.yandex.ru/authorize?client_id='.$this->appId.'&response_type=code&redirect_uri='.$redirect_url;
  }

  /**
   * @param $code
   * @throws AccessTokenEmpty
   */
  function fetchUserData($code) {
    $authTokenUrlBase = 'https://oauth.yandex.ru/token';
    $params = array(
      'client_id' => $this->appId,
      'client_secret' => $this->secret,
      'redirect_uri' => $this->getRedirectUrl(),
      'code' => $code,
      'grant_type' => 'authorization_code'
    );
    $authTokenCurl = curl_init($authTokenUrlBase);
    curl_setopt($authTokenCurl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($authTokenCurl, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($authTokenCurl, CURLOPT_HTTPHEADER, array('Content-type: application/x-www-form-urlencoded'));
    curl_setopt($authTokenCurl, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($authTokenCurl, CURLOPT_ENCODING, "");
    $authTokenResponse = curl_exec($authTokenCurl);
    $authTokenParams = json_decode($authTokenResponse, true);
    curl_close($authTokenCurl);
    if (empty($authTokenParams['access_token'])) {
      throw new AccessTokenEmpty();
    }
    $this->accessToken = $authTokenParams['access_token'];
    $this->fetchByToken($authTokenParams['access_token']);
  }

  function fetchByToken($accessToken, $params = null) {
    $authInfoUrlBase = 'https://login.yandex.ru/info';
    $params = array(
      'format' => 'json',
      'oauth_token' => $accessToken,
    );
    $authInfoCurl = curl_init($authInfoUrlBase);
    curl_setopt($authInfoCurl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($authInfoCurl, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($authInfoCurl, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($authInfoCurl, CURLOPT_ENCODING, "");
    $authInfoResponse = curl_exec($authInfoCurl);
    $authInfoParams = json_decode($authInfoResponse, true);
    curl_close($authInfoCurl);
    $this->parseData($authInfoParams);
  }

  function parseData($userInfo, $params = null) {
    $this->provider = 'yandex';
    $this->providerKey = $userInfo['id'];
    if (isset($userInfo['login'])) {
      $this->user = $userInfo['login'];
    } elseif (isset($userInfo['real_name'])) {
      $this->user = $userInfo['real_name'];
    }
    if (isset($userInfo['default_avatar_id'])) {
      $this->userpic = 'https://avatars.yandex.net/get-yapic/'.$userInfo['default_avatar_id'].'/islands-200';
    }
    $this->sex = ($userInfo['sex'] == 'male');
  }
}