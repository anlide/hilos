<?php

namespace Hilos\Daemon\Client\WebSocketProtocol;

use Exception;
use Hilos\Daemon\Client\Client;
use Hilos\Daemon\Client\WebSocket;

/**
 * @link http://tools.ietf.org/html/rfc6455
 * Class V13
 */
class V13 extends WebSocketProtocol {
  const CONTINUATION = 0;
  const STRING       = 0x1;
  const BINARY       = 0x2;
  const CONNCLOSE    = 0x8;
  const PING         = 0x9;
  const PONG         = 0xA;
  protected static array $opcodes = [
    0   => 'CONTINUATION',
    0x1 => 'STRING',
    0x2 => 'BINARY',
    0x8 => 'CONNCLOSE',
    0x9 => 'PING',
    0xA => 'PONG',
  ];
  protected int $outgoingCompression = 0;

  protected string $framebuf = '';

  /**
   * Called when new data received
   * @see http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#page-16
   * @return void
   * @throws Exception
   */
  function handle() {
    if ($this->client->getState() === WebSocket::STATE_PREHANDSHAKE) {
      if (!$this->client->handshake()) {
        return;
      }
    }
    if ($this->client->getState() === WebSocket::STATE_HANDSHAKED) {
      while (($buflen = strlen($this->client->getUnparsedData())) >= 2) {
        $first = ord($this->client->look(1)); // first byte integer (fin, opcode)
        $firstBits = decbin($first);
        $opcode = (int)bindec(substr($firstBits, 4, 4));
        if ($opcode === self::CONNCLOSE) {
          $this->client->close(Client::CLOSE_NORMAL);
          return;
        }
        $opcodeName = static::$opcodes[$opcode] ?? false;
        if (!$opcodeName) {
          error_log(get_class($this) . ': Undefined opcode ' . $opcode);
          $this->client->close(Client::CLOSE_PROTOCOL);
          return;
        }
        $second = ord($this->client->look(1, 1)); // second byte integer (masked, payload length)
        $fin = (bool)($first >> 7);
        $isMasked = (bool)($second >> 7);
        $dataLength = $second & 0x7f;
        $p = 2;
        if ($dataLength === 0x7e) { // 2 bytes-length
          if ($buflen < $p + 2) {
            return; // not enough data yet
          }
          $dataLength = Client::bytes2int($this->client->look(2, $p), false);
          $p += 2;
        } elseif ($dataLength === 0x7f) { // 8 bytes-length
          if ($buflen < $p + 8) {
            return; // not enough data yet
          }
          $dataLength = Client::bytes2int($this->client->look(8, $p));
          $p += 8;
        }
        if (WebSocket::MAX_ALLOWED_PACKET <= $dataLength) {
          // Too big packet
          $this->client->close(Client::CLOSE_TOO_BIG);
          return;
        }
        if ($isMasked) {
          if ($buflen < $p + 4) {
            return; // not enough data yet
          }
          $mask = $this->client->look(4, $p);
          $p += 4;
        }
        if ($buflen < $p + $dataLength) {
          return; // not enough data yet
        }
        $this->client->drain($p);
        $data = $this->client->read($dataLength);
        if ($isMasked) {
          $data = $this->mask($data, $mask);
        }
        if (!$fin) {
          $this->framebuf .= $data;
        } else {
          $this->framebuf .= $data;
          switch ($opcode) {
            case self::CONTINUATION:
            case self::STRING:
            case self::BINARY:
              $this->client->onFrame($this->framebuf, $opcodeName);
              break;
            case self::PING:
              $this->sendFrame($this->framebuf, self::PONG);
              break;
            case self::PONG:
              break;
            default:
              $this->client->close(Client::CLOSE_PROTOCOL);
              break;
          }
          $this->framebuf = '';
        }
      }
    }
  }

  /**
   * Sends a handshake message reply
   * @return boolean OK?
   * @throws Exception
   */
  public function sendHandshakeReply(): bool {
    if (!$this->client->issetServerKey('HTTP_SEC_WEBSOCKET_KEY') || !$this->client->issetServerKey('HTTP_SEC_WEBSOCKET_VERSION')) {
      return false;
    }
    if ($this->client->getServerKey('HTTP_SEC_WEBSOCKET_VERSION') !== '13' && $this->client->getServerKey('HTTP_SEC_WEBSOCKET_VERSION') !== '8') {
      return false;
    }

    if ($this->client->issetServerKey('HTTP_ORIGIN')) {
      $this->client->setServerKey('HTTP_SEC_WEBSOCKET_ORIGIN', $this->client->getServerKey('HTTP_ORIGIN'));
    }
    if (!$this->client->issetServerKey('HTTP_SEC_WEBSOCKET_ORIGIN')) {
      $this->client->setServerKey('HTTP_SEC_WEBSOCKET_ORIGIN', '');
    }
    $this->client->write("HTTP/1.1 101 Switching Protocols\r\n"
      . "Upgrade: WebSocket\r\n"
      . "Connection: Upgrade\r\n"
      . "Date: " . date('r') . "\r\n"
      . "Sec-WebSocket-Origin: " . $this->client->getServerKey('HTTP_SEC_WEBSOCKET_ORIGIN') . "\r\n"
      . "Sec-WebSocket-Location: ws://" . $this->client->getServerKey('HTTP_HOST') . $this->client->getServerKey('REQUEST_URI') . "\r\n"
      . "Sec-WebSocket-Accept: " . base64_encode(sha1(trim($this->client->getServerKey('HTTP_SEC_WEBSOCKET_KEY')) . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true)) . "\r\n"
    );
    if ($this->client->issetServerKey('HTTP_SEC_WEBSOCKET_PROTOCOL')) {
      $this->client->write("Sec-WebSocket-Protocol: " . $this->client->getServerKey('HTTP_SEC_WEBSOCKET_PROTOCOL')."\r\n");
    }

    $this->client->write("\r\n");

    return true;
  }

  /**
   * Sends a frame.
   * @param  string   $data  Frame's data.
   * @param  string   $type  Frame's type. ("STRING" OR "BINARY")
   * @return boolean         Success.
   * @throws Exception
   */
  public function sendFrame($data, $type = null): bool {
    if (!$this->client->getHandshaked()) {
      return false;
    }

    if ($this->client->closed() && $type !== 'CONNCLOSE') {
      return false;
    }

    $fin = 1;
    $rsv1 = 0;
    $rsv2 = 0;
    $rsv3 = 0;
    $this->client->write(chr(bindec($fin . $rsv1 . $rsv2 . $rsv3 . str_pad(decbin($this->getFrameType($type)), 4, '0', STR_PAD_LEFT))));
    if (is_array($data)) {
      $data = json_encode($data);
    }
    $dataLength  = strlen($data);
    $isMasked    = false;
    $isMaskedInt = $isMasked ? 128 : 0;
    if ($dataLength <= 125) {
      $this->client->write(chr($dataLength + $isMaskedInt));
    } elseif ($dataLength <= 65535) {
      $this->client->write(chr(126 + $isMaskedInt) . // 126 + 128
        chr($dataLength >> 8) .
        chr($dataLength & 0xFF));
    } else {
      $this->client->write(chr(127 + $isMaskedInt) . // 127 + 128
        chr($dataLength >> 56) .
        chr($dataLength >> 48) .
        chr($dataLength >> 40) .
        chr($dataLength >> 32) .
        chr($dataLength >> 24) .
        chr($dataLength >> 16) .
        chr($dataLength >> 8) .
        chr($dataLength & 0xFF));
    }
    if ($isMasked) {
      $mask    = chr(mt_rand(0, 0xFF)) .
        chr(mt_rand(0, 0xFF)) .
        chr(mt_rand(0, 0xFF)) .
        chr(mt_rand(0, 0xFF));
      $this->client->write($mask . $this->mask($data, $mask));
    } else {
      $this->client->write($data);
    }
    return true;
  }

  /**
   * Apply mask
   * @param $data
   * @param string|false $mask
   * @return mixed
   */
  public function mask($data, $mask) {
    for ($i = 0, $l = strlen($data), $ml = strlen($mask); $i < $l; $i++) {
      $data[$i] = $data[$i] ^ $mask[$i % $ml];
    }

    return $data;
  }
}