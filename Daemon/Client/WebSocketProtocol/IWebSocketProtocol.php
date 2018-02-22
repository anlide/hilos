<?php
namespace Hilos\Daemon\Client\WebSocketProtocol;

interface IWebSocketProtocol {
  function handle();
  function sendHandshakeReply();
  function sendFrame($data, $type = null);
}