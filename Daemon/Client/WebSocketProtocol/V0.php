<?php

namespace Hilos\Daemon\Client\WebSocketProtocol;

use Hilos\Daemon\Client\WebSocket;

/**
 * @link http://tools.ietf.org/html/draft-hixie-thewebsocketprotocol-76
 * Class V0
 */
class V0 extends WebSocketProtocol {
  const STRING = 0x00;
  const BINARY = 0x80;

  protected $key;

  function handle() {
    if ($this->client->getState() === WebSocket::STATE_PREHANDSHAKE) {
      if (strlen($this->client->getUnparsedData()) < 8) {
        return;
      }
      $this->key = $this->client->readUnlimited();
      $this->client->handshake();
    }
    if ($this->client->getState() === WebSocket::STATE_HANDSHAKED) {
      while (($buflen = strlen($this->client->getUnparsedData())) >= 2) {
        $hdr = $this->client->look(10);
        $frametype = ord(substr($hdr, 0, 1));
        if (($frametype & 0x80) === 0x80) {
          $len = 0;
          $i = 0;
          do {
            if ($buflen < $i + 1) {
              // not enough data yet
              return;
            }
            $b = ord(substr($hdr, ++$i, 1));
            $n = $b & 0x7F;
            $len *= 0x80;
            $len += $n;
          } while ($b > 0x80);

          if (WebSocket::MAX_ALLOWED_PACKET <= $len) {
            // Too big packet
            $this->client->close(WebSocket::CLOSE_TOO_BIG);
            return;
          }

          if ($buflen < $len + $i + 1) {
            // not enough data yet
            return;
          }
          $this->client->drain($i + 1);
          $this->client->onFrame($this->client->read($len), 'BINARY');
        } else {
          if (($p = $this->client->search("\xFF")) !== false) {
            if (WebSocket::MAX_ALLOWED_PACKET <= $p - 1) {
              // Too big packet
              $this->client->close(WebSocket::CLOSE_TOO_BIG);
              return;
            }
            $this->client->drain(1);
            $data = $this->client->read($p);
            $this->client->drain(1);
            $this->client->onFrame($data, 'STRING');
          } else {
            if (WebSocket::MAX_ALLOWED_PACKET < $buflen - 1) {
              // Too big packet
              $this->client->close(WebSocket::CLOSE_TOO_BIG);
              return;
            }
            // not enough data yet
            return;
          }
        }
      }
    }
  }

  function sendHandshakeReply() {
    if (!$this->client->issetServerKey('HTTP_SEC_WEBSOCKET_KEY1') || !$this->client->issetServerKey('HTTP_SEC_WEBSOCKET_KEY2')) {
      return false;
    }
    $final_key = $this->_computeFinalKey($this->client->getServerKey('HTTP_SEC_WEBSOCKET_KEY1'), $this->client->getServerKey('HTTP_SEC_WEBSOCKET_KEY2'), $this->key);
    $this->key = null;

    if (!$final_key) {
      return false;
    }

    if (!$this->client->issetServerKey('HTTP_SEC_WEBSOCKET_ORIGIN')) {
      $this->client->setServerKey('HTTP_SEC_WEBSOCKET_ORIGIN', '');
    }

    $this->client->write("HTTP/1.1 101 Web Socket Protocol Handshake\r\n"
      . "Upgrade: WebSocket\r\n"
      . "Connection: Upgrade\r\n"
      . "Sec-WebSocket-Origin: " . $this->client->getServerKey('HTTP_ORIGIN') . "\r\n"
      . "Sec-WebSocket-Location: ws://" . $this->client->getServerKey('HTTP_HOST') . $this->client->getServerKey('REQUEST_URI') . "\r\n");
    if ($this->client->issetServerKey('HTTP_SEC_WEBSOCKET_PROTOCOL')) {
      $this->client->write("Sec-WebSocket-Protocol: " . $this->client->getServerKey('HTTP_SEC_WEBSOCKET_PROTOCOL')."\r\n");
    }
    $this->client->write("\r\n" . $final_key);
    return true;
  }
  function sendFrame($data, $type = null) {
    if (!$this->client->getHandshaked()) {
      return false;
    }

    if ($this->client->closed() && $type !== 'CONNCLOSE') {
      return false;
    }
    if ($type === 'CONNCLOSE') {
      $this->client->close(WebSocket::CLOSE_NORMAL);
      return true;
    }

    $type = $this->getFrameType($type);
    // Binary
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

  /**
   * Computes final key for Sec-WebSocket.
   * @param string $key1 Key1
   * @param string $key2 Key2
   * @param string $data Data
   * @return string Result
   */
  protected function _computeFinalKey($key1, $key2, $data) {
    if (strlen($data) < 8) {
      error_log(get_class($this) . '::' . __METHOD__ . ' : Invalid handshake data for client "'.$this->client->getIp().'"');
      return false;
    }
    return md5($this->_computeKey($key1) . $this->_computeKey($key2) . substr($data, 0, 8), true);
  }

  /**
   * Computes key for Sec-WebSocket.
   * @param string $key Key
   * @return string Result
   */
  protected function _computeKey($key) {
    $spaces = 0;
    $digits = '';

    for ($i = 0, $s = strlen($key); $i < $s; ++$i) {
      $c = substr($key, $i, 1);

      if ($c === "\x20") {
        ++$spaces;
      } elseif (ctype_digit($c)) {
        $digits .= $c;
      }
    }

    if ($spaces > 0) {
      $result = (float)floor($digits / $spaces);
    } else {
      $result = (float)$digits;
    }

    return pack('N', $result);
  }

}