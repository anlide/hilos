<?php
namespace Hilos\Service\OAuth;

use Hilos\Service\Exception\OAuth\AccessTokenEmpty;
use Hilos\Service\OAuth;

/**
 * Class Ok
 * @package Hilos\Service\OAuth
 */
class Ok extends OAuth {
  protected ?string $public;

  function getRedirectUrl(): string {
    return $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['SERVER_NAME'].'/oauth/method=ok';
  }

  function getUrl(): string {
    $redirect_url = urlencode($this->getRedirectUrl());
    return 'https://connect.ok.ru/oauth/authorize?client_id='.$this->appId.'&response_type=code&redirect_uri='.$redirect_url;
  }

  /**
   * @param $code
   * @throws AccessTokenEmpty
   */
  function fetchUserData($code) {
    $authTokenUrl = 'https://api.odnoklassniki.ru/oauth/token.do?client_id='.$this->appId.'&client_secret='.$this->secret.'&redirect_uri='.$this->getRedirectUrl().'&grant_type=authorization_code&code='.$code;
    $authTokenCurl = curl_init($authTokenUrl);
    curl_setopt($authTokenCurl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($authTokenCurl, CURLOPT_CUSTOMREQUEST, 'POST');
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
    $authInfoUrlBase = 'http://api.ok.ru/fb.do';
    $params = array(
      'application_key' => $this->public,
      'method' => 'users.getCurrentUser',
      'access_token' => $accessToken,
      'sig' => md5('application_key='.$this->public.'method=users.getCurrentUser'.md5($accessToken.$this->secret))
    );
    $authInfoCurl = curl_init($authInfoUrlBase.'?'.http_build_query($params));
    curl_setopt($authInfoCurl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($authInfoCurl, CURLOPT_CUSTOMREQUEST, 'GET');
    $authInfoResponse = curl_exec($authInfoCurl);
    $authInfoParams = json_decode($authInfoResponse, true);
    curl_close($authInfoCurl);
    $this->parseData($authInfoParams);
  }

  function parseData($userInfo, $params = null) {
    $this->provider = 'ok';
    $this->providerKey = $userInfo['uid'];
    if (isset($userInfo['name'])) {
      $this->user = $userInfo['name'];
    }
    if (isset($userInfo['pic_1'])) {
      $this->userPic = $userInfo['pic_1'];
    }
    $this->sex = ($userInfo['gender'] == 'male');
  }

  public function fetchBySession($sessionKey, $sessionSecretKey) {
    $authInfoUrlBase = 'http://api.ok.ru/fb.do';
    $params = array(
      'application_key' => $this->public,
      'method' => 'users.getCurrentUser',
      'session_key' => $sessionKey,
      'sig' => md5('application_key='.$this->public.'method=users.getCurrentUser'.'session_key='.$sessionKey.$sessionSecretKey)
    );
    $authInfoCurl = curl_init($authInfoUrlBase.'?'.http_build_query($params));
    curl_setopt($authInfoCurl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($authInfoCurl, CURLOPT_CUSTOMREQUEST, 'GET');
    $authInfoResponse = curl_exec($authInfoCurl);
    $authInfoParams = json_decode($authInfoResponse, true);
    curl_close($authInfoCurl);
    $this->parseData($authInfoParams);
  }
}