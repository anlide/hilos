<?php
namespace Hilos\Service\OAuth;

use Hilos\Service\Exception\OAuth\AccessTokenEmpty;
use Hilos\Service\OAuth;

/**
 * Class Google
 * @package Hilos\Service\OAuth
 */
class Google extends OAuth {
  function getRedirectUrl(): string {
    return $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['SERVER_NAME'].'/oauth/method=google';
  }

  function getUrl(): string {
    $redirect_url = urlencode($this->getRedirectUrl());
    return 'https://accounts.google.com/o/oauth2/auth?client_id='.$this->appId.'&response_type=code&scope=openid&redirect_uri='.$redirect_url;
  }

  /**
   * @param $code
   * @throws AccessTokenEmpty
   */
  function fetchUserData($code) {
    $authTokenUrlBase = 'https://accounts.google.com/o/oauth2/token';
    $params = array(
      'code' => $code,
      'client_id' => $this->appId,
      'client_secret' => $this->secret,
      'redirect_uri' => $this->getRedirectUrl(),
      'grant_type' => 'authorization_code'
    );
    $authTokenCurl = curl_init($authTokenUrlBase);
    curl_setopt($authTokenCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($authTokenCurl, CURLOPT_POST, true);
    curl_setopt($authTokenCurl, CURLOPT_POSTFIELDS, http_build_query($params));
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
    $userDataUrl = 'https://www.googleapis.com/plus/v1/people/me?access_token='.$accessToken;
    $userDataCurl = curl_init($userDataUrl);
    curl_setopt($userDataCurl, CURLOPT_RETURNTRANSFER, true);
    $userDataResponse = curl_exec($userDataCurl);
    curl_close($userDataCurl);
    $this->parseData(json_decode($userDataResponse, true));
  }

  function parseData($userInfo, $params = null) {
    $this->provider = 'google';
    $this->providerKey = $userInfo['id'];
    if (isset($userInfo['tagline'])) {
      $this->user = $userInfo['tagline'];
    } elseif (isset($userInfo['displayName'])) {
      $this->user = $userInfo['displayName'];
    }
    if (isset($userInfo['image']['url'])) {
      $tmp = parse_url($userInfo['image']['url']);
      $this->userPic = $tmp['scheme'].'://'.$tmp['host'].$tmp['path'];
    }
    $this->sex = ($userInfo['gender'] == 'male');
  }
}