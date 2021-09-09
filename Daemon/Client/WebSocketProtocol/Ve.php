<?php

namespace Hilos\Daemon\Client\WebSocketProtocol;

use Exception;
use Hilos\Daemon\Client\Client;
use Hilos\Daemon\Client\WebSocket;

class Ve extends WebSocketProtocol {
  const STRING = 0x00;
  const BINARY = 0x80;

  function handle() {
    while (($buflen = strlen($this->client->getUnparsedData())) >= 2) {
      $hdr       = $this->client->look(10);
      $frametype = ord(substr($hdr, 0, 1));
      if (($frametype & 0x80) === 0x80) {
        $len = 0;
        $i   = 0;
        do {
          if ($buflen < $i + 1) {
            return;
          }
          $b = ord(substr($hdr, ++$i, 1));
          $n = $b & 0x7F;
          $len *= 0x80;
          $len += $n;
        } while ($b > 0x80);

        if (WebSocket::MAX_ALLOWED_PACKET <= $len) {
          // Too big packet
          $this->client->close(Client::CLOSE_TOO_BIG);
          return;
        }

        if ($buflen < $len + $i + 1) {
          // not enough data yet
          return;
        }

        $this->client->drain($i + 1);
        $this->client->onFrame($this->client->read($len), $frametype);
      } else {
        if (($p = $this->client->search("\xFF")) !== false) {
          if (WebSocket::MAX_ALLOWED_PACKET <= $p - 1) {
            // Too big packet
            $this->client->close(Client::CLOSE_TOO_BIG);
            return;
          }
          $this->client->drain(1);
          $data = $this->client->read($p);
          $this->client->drain(1);
          $this->client->onFrame($data, 'STRING');
        } else {
          if (WebSocket::MAX_ALLOWED_PACKET < $buflen - 1) {
            // Too big packet
            $this->client->close(Client::CLOSE_TOO_BIG);
            return;
          }
        }
      }
    }
  }

  /**
   * @throws Exception
   */
  function sendHandshakeReply(): bool {
    if (!$this->client->issetServerKey('HTTP_SEC_WEBSOCKET_ORIGIN')) {
      $this->client->setServerKey('HTTP_SEC_WEBSOCKET_ORIGIN', '');
    }

    $this->client->write("HTTP/1.1 101 Web Socket Protocol Handshake\r\n"
      . "Upgrade: WebSocket\r\n"
      . "Connection: Upgrade\r\n"
      . "Sec-WebSocket-Origin: " . $this->client->getServerKey('HTTP_ORIGIN') . "\r\n"
      . "Sec-WebSocket-Location: ws://" . $this->client->getServerKey('HTTP_HOST') . $this->client->getServerKey('REQUEST_URI') . "\r\n"
    );
    if ($this->client->issetServerKey('HTTP_SEC_WEBSOCKET_PROTOCOL')) {
      $this->client->write("Sec-WebSocket-Protocol: " . $this->client->getServerKey('HTTP_SEC_WEBSOCKET_PROTOCOL')."\r\n");
    }
    $this->client->write("\r\n");
    return true;
  }

  /**
   * @throws Exception
   */
  function sendFrame($data, $type = null): bool {
    if (!$this->client->getHandshaked()) {
      return false;
    }

    if ($this->client->closed() && $type !== 'CONNCLOSE') {
      return false;
    }

    if ($type === 'CONNCLOSE') {
      $this->client->close(Client::CLOSE_NORMAL);
      return true;
    }

    // Binary
    $type = $this->getFrameType($type);
    if (($type & self::BINARY) === self::BINARY) {
      $n   = strlen($data);
      $len = '';
      $pos = 0;

      char:

      ++$pos;
      $c = $n >> 0 & 0x7F;
      $n >>= 7;

      if ($pos !== 1) {
        $c += 0x80;
      }

      if ($c !== 0x80) {
        $len = chr($c) . $len;
        goto char;
      };

      $this->client->write(chr(self::BINARY) . $len . $data);
    }
    // String
    else {
      $this->client->write(chr(self::STRING) . $data . "\xFF");
    }
    return true;
  }
}