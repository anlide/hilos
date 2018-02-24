<?php
namespace Hilos\Service\OAuth;

use Hilos\Service\Exception\OAuth\AccessTokenEmpty;
use Hilos\Service\OAuth;

class Yahoo extends OAuth {
  function getRedirectUrl() {
    return $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['SERVER_NAME'].'/oauth/method=yahoo';
  }
  function getUrl() {
    $redirect_url = urlencode($this->getRedirectUrl());
    return 'https://api.login.yahoo.com/oauth2/request_auth?client_id='.$this->appId.'&response_type=code&redirect_uri='.$redirect_url;
  }
  function fetchUserData($code) {
    $authTokenUrlBase = 'https://api.login.yahoo.com/oauth2/get_token';
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
    if (empty($authTokenParams['xoauth_yahoo_guid'])) {
      throw new AccessTokenEmpty();
    }
    $this->accessToken = $authTokenParams['access_token'];
    $this->fetchByToken($authTokenParams['access_token'], $authTokenParams['xoauth_yahoo_guid']);
  }
  function fetchByToken($accessToken, $params = null) {
    $authInfoUrlBase = 'https://social.yahooapis.com/v1/user/';
    $url = $authInfoUrlBase.$params.'/profile?format=json';
    $authInfoCurl = curl_init($url);
    curl_setopt($authInfoCurl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($authInfoCurl, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($authInfoCurl, CURLOPT_HTTPHEADER, array(
      'Content-type: application/x-www-form-urlencoded',
      'Authorization: Bearer '.$accessToken
    ));
    curl_setopt($authInfoCurl, CURLOPT_ENCODING, "");
    $authInfoResponse = curl_exec($authInfoCurl);
    curl_close($authInfoCurl);
    $this->parseData(json_decode($authInfoResponse, true), $params);
  }
  function parseData($userInfo, $params = null) {
    $this->provider = 'yahoo';
    $this->providerKey = $params;
    if (isset($userInfo['profile']['nickname'])) {
      $this->user = $userInfo['profile']['nickname'];
    }
    if (isset($userInfo['profile']['image']['imageUrl'])) {
      $this->userpic = $userInfo['profile']['image']['imageUrl'];
    }
  }
}