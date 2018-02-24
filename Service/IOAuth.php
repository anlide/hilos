<?php
namespace Hilos\Service;

interface IOAuth {
  function fetchUserData($code);
  function fetchByToken($accessToken, $params = null);
  function parseData($userInfo, $params = null);
  function toArray();
  function getUrl();
  function getRedirectUrl();
  function getAccessToken();
}