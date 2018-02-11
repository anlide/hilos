<?php
namespace Hilos\Deamon\Socket;

/**
 * @link http://tools.ietf.org/html/draft-hixie-thewebsocketprotocol-76
 * Class V0
 */
class V0 extends Websocket {
  const STRING = 0x00;
  const BINARY = 0x80;

  protected $key;

  /**
   * Sends a handshake message reply
   * @param string $extraHeaders Received data (no use in this class)
   * @return boolean OK?
   */
  public function sendHandshakeReply($extraHeaders = '') {
    if (!isset($this->server['HTTP_SEC_WEBSOCKET_KEY1']) || !isset($this->server['HTTP_SEC_WEBSOCKET_KEY2'])) {
      return false;
    }
    $final_key = $this->_computeFinalKey($this->server['HTTP_SEC_WEBSOCKET_KEY1'], $this->server['HTTP_SEC_WEBSOCKET_KEY2'], $this->key);
    $this->key = null;

    if (!$final_key) {
      return false;
    }

    if (!isset($this->server['HTTP_SEC_WEBSOCKET_ORIGIN'])) {
      $this->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = '';
    }

    $this->write("HTTP/1.1 101 Web Socket Protocol Handshake\r\n"
      . "Upgrade: WebSocket\r\n"
      . "Connection: Upgrade\r\n"
      . "Sec-WebSocket-Origin: " . $this->server['HTTP_ORIGIN'] . "\r\n"
      . "Sec-WebSocket-Location: ws://" . $this->server['HTTP_HOST'] . $this->server['REQUEST_URI'] . "\r\n");
    if (isset($this->server['HTTP_SEC_WEBSOCKET_PROTOCOL'])) {
      $this->write("Sec-WebSocket-Protocol: " . $this->server['HTTP_SEC_WEBSOCKET_PROTOCOL']."\r\n");
    }
    $this->write($extraHeaders . "\r\n" . $final_key);
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
      error_log(get_class($this) . '::' . __METHOD__ . ' : Invalid handshake data for client ""'); // $this->addr
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

  /**
   * Sends a frame.
   * @param  string   $data  Frame's data.
   * @param  string   $type  Frame's type. ("STRING" OR "BINARY")
   * @param  callable $cb    Optional. Callback called when the frame is received by client.
   * @callback $cb ( )
   * @return boolean         Success.
   */
  public function sendFrame($data, $type = null, $cb = null) {
    if (!$this->handshaked) {
      return false;
    }

    if ($this->closed && $type !== 'CONNCLOSE') {
      return false;
    }
    if ($type === 'CONNCLOSE') {
      if ($cb !== null) {
        $cb($this);
        return true;
      }
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

      $this->write(chr(self::BINARY) . $len . $data);
    }
    // String
    else {
      $this->write(chr(self::STRING) . $data . "\xFF");
    }
    if ($cb !== null) {
      $cb();
    }
    return true;
  }

  /**
   * Called when new data received
   * @return void
   */
  public function onRead() {
    if ($this->state === self::STATE_PREHANDSHAKE) {
      if (strlen($this->unparsedData) < 8) {
        return;
      }
      $this->key = $this->readUnlimited();
      $this->handshake();
    }
    if ($this->state === self::STATE_HANDSHAKED) {
      while (($buflen = strlen($this->unparsedData)) >= 2) {
        $hdr = $this->look(10);
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

          if (self::MAX_ALLOWED_PACKET <= $len) {
            // Too big packet
            $this->close(self::CLOSE_TOO_BIG);
            return;
          }

          if ($buflen < $len + $i + 1) {
            // not enough data yet
            return;
          }
          $this->drain($i + 1);
          $this->onFrame($this->read($len), 'BINARY');
        } else {
          if (($p = $this->search("\xFF")) !== false) {
            if (self::MAX_ALLOWED_PACKET <= $p - 1) {
              // Too big packet
              $this->close(self::CLOSE_TOO_BIG);
              return;
            }
            $this->drain(1);
            $data = $this->read($p);
            $this->drain(1);
            $this->onFrame($data, 'STRING');
          } else {
            if (self::MAX_ALLOWED_PACKET < $buflen - 1) {
              // Too big packet
              $this->close(self::CLOSE_TOO_BIG);
              return;
            }
            // not enough data yet
            return;
          }
        }
      }
    }
  }
}