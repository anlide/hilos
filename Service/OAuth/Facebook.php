<?php
namespace Hilos\Service\OAuth;

use Hilos\Service\Exception\OAuth\AccessTokenEmpty;
use Hilos\Service\OAuth;

/**
 * Class Facebook
 * @package Hilos\Service\OAuth
 */
class Facebook extends OAuth {
  function getRedirectUrl() {
    return $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['SERVER_NAME'].'/oauth/method=facebook';
  }

  function getUrl() {
    $redirect_url = urlencode($this->getRedirectUrl());
    return 'https://www.facebook.com/dialog/oauth?client_id='.$this->appId.'&response_type=code&redirect_uri='.$redirect_url;
  }

  /**
   * @param $code
   * @throws AccessTokenEmpty
   */
  function fetchUserData($code) {
    $authTokenUrl = 'https://graph.facebook.com/oauth/access_token?client_id='.$this->appId.'&redirect_uri='.$this->getRedirectUrl().'&client_secret='.$this->secret.'&code='.$code;
    $authTokenCurl = curl_init($authTokenUrl);
    curl_setopt($authTokenCurl, CURLOPT_RETURNTRANSFER, true);
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
    $userDataUrl = 'https://graph.facebook.com/me?fields=name,first_name,last_name,gender,picture.type(large)&access_token='.$accessToken;
    $userDataCurl = curl_init($userDataUrl);
    curl_setopt($userDataCurl, CURLOPT_RETURNTRANSFER, true);
    $userDataResponse = curl_exec($userDataCurl);
    curl_close($userDataCurl);
    $userInfo = json_decode($userDataResponse, true);
    $this->parseData($userInfo);
  }

  function parseData($userInfo, $params = null) {
    $this->provider = 'facebook';
    $this->providerKey = $userInfo['id'];
    if (isset($userInfo['name'])) {
      $this->user = $userInfo['name'];
    }
    if (isset($userInfo['gender'])) {
      $this->sex = ($userInfo['gender'] == 'male');
    }
    if (isset($userInfo['picture']['data']['url'])) {
      $this->userpic = $userInfo['picture']['data']['url'];
    }
  }
}