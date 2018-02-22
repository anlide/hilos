<?php

namespace Hilos\Daemon\Client;

abstract class Client implements IClient {
  protected static $hvaltr = ['; ' => '&', ';' => '&', ' ' => '%20'];

  const STATE_STANDBY = 0;

  const CLOSE_NORMAL      = 1000;
  const CLOSE_GOING_AWAY  = 1001;
  const CLOSE_PROTOCOL    = 1002;
  const CLOSE_BAD_DATA    = 1003;
  const CLOSE_NO_STATUS   = 1005;
  const CLOSE_ABNORMAL    = 1006;
  const CLOSE_BAD_PAYLOAD = 1007;
  const CLOSE_POLICY      = 1008;
  const CLOSE_TOO_BIG     = 1009;
  const CLOSE_MAND_EXT    = 1010;
  const CLOSE_SRV_ERR     = 1011;
  const CLOSE_SESSION     = 1012;
  const CLOSE_TLS         = 1015;

  const MAX_BUFFER_SIZE = 1024 * 1024;

  protected $extensionsCleanRegex = '/(?:^|\W)x-webkit-/iS';

  protected $socket;
  protected $closed = false;
  protected $state = self::STATE_STANDBY;
  protected $unparsedData = '';
  protected $closeStatus;

  /** @var array _SERVER */
  protected $server = [];

  function getSocket() {
    return $this->socket;
  }

  function getState() {
    return $this->state;
  }

  function getUnparsedData() {
    return $this->unparsedData;
  }

  function getServerKey($key) {
    return $this->server[$key];
  }

  function setServerKey($key, $value) {
    $this->server[$key] = $value;
  }

  function issetServerKey($key) {
    return isset($this->server[$key]);
  }

  public function close($reason = self::CLOSE_NO_STATUS) {
    if ($this->closed) return;
    $this->closeStatus = $reason;
    socket_close($this->socket);
    $this->closed = true;
  }

  public function closed() {
    return $this->closed;
  }

  public function stop() {
    $this->close(self::CLOSE_NORMAL);
  }

  protected final function receiveData() {
    if ($this->closed) return;
    socket_clear_error($this->socket);
    $data = socket_read($this->socket, self::MAX_BUFFER_SIZE);
    if (is_string($data) && !empty($data)) {
      $this->unparsedData .= $data;
    } elseif ($this instanceof Websocket) {
      if (in_array(socket_last_error(), array(32, 104))) {
        $this->close(self::CLOSE_GOING_AWAY);
      } else {
        $this->close(self::CLOSE_ABNORMAL);
      }
      return;
    }
  }

  /**
   * Searches first occurence of the string in input buffer
   * @param  string  $what  Needle
   * @param  integer $start Offset start
   * @param  integer $end   Offset end
   * @return integer        Position
   */
  public function search($what, $start = 0, $end = -1) {
    return strpos($this->unparsedData, $what, $start);
  }

  /**
   * Reads all data from the connection's buffer
   * @return string Readed data
   */
  public function readUnlimited() {
    $ret = $this->unparsedData;
    $this->unparsedData = '';
    return $ret;
  }

  /**
   * Read data from the connection's buffer
   * @param  integer      $n Max. number of bytes to read
   * @return string|false    Readed data
   */
  public function read($n) {
    if ($n <= 0) {
      return '';
    }
    $read = $this->drain($n);
    if ($read === '') {
      return false;
    }
    return $read;
  }

  /**
   * Drains buffer
   * @param  integer $n Numbers of bytes to drain
   * @return boolean    Success
   */
  public function drain($n) {
    $ret = substr($this->unparsedData, 0, $n);
    $this->unparsedData = substr($this->unparsedData, $n);
    return $ret;
  }

  /**
   * Read from buffer without draining
   * @param integer $n Number of bytes to read
   * @param integer $o Offset
   * @return string|false
   */
  public function look($n, $o = 0) {
    if (strlen($this->unparsedData) <= $o) {
      return '';
    }
    return substr($this->unparsedData, $o, $n);
  }

  /**
   * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
   * @param string $data Data to send
   * @return bool|int
   * @throws \Exception
   */
  public function write($data) {
    if ($this->closed) return false;
    $bytesLeft = $total = strlen($data);
    $tryCount = 0;
    do {
      socket_clear_error($this->socket);
      $sended = socket_write($this->socket, $data, 65536 - 1);
      if ($sended === false) {
        if (socket_strerror( socket_last_error()) == 'Resource temporarily unavailable') {
          $tryCount++;
          if ($tryCount >= 1000) {
            error_log('Hilos socket write error: resource temporarily unavailable too much times');
            $this->close(self::CLOSE_ABNORMAL);
            return false;
          }
          sleep(0);
          continue;
        } else {
          if (socket_last_error() == 104) {
            $this->close(self::CLOSE_GOING_AWAY);
            return false;
          }
          if (socket_last_error() == 32) {
            $this->close(self::CLOSE_GOING_AWAY);
            return false;
          }
          error_log('Hilos socket write error: ' . socket_strerror( socket_last_error()));
          $this->close(self::CLOSE_ABNORMAL);
          return false;
        }
      }
      $bytesLeft -= $sended;
      $data = substr($data, $sended);
      $tryCount++;
      if ($tryCount >= 100) {
        error_log('Hilos socket write error: too much amount of tries');
        $this->close(self::CLOSE_ABNORMAL);
        return false;
      }
    } while ($bytesLeft > 0);
    return $total;
  }

  /**
   * Send Bad request
   * @return void
   */
  public function badRequest() {
    $this->write("400 Bad Request\r\n\r\n<html><head><title>400 Bad Request</title></head><body bgcolor=\"white\"><h1>400 Bad Request</h1></body></html>");
    $this->close(self::CLOSE_BAD_DATA);
  }

  protected function readLine($explode = "\n") {
    $lines = explode($explode, $this->unparsedData);
    $firstLine = $lines[0];
    if (strlen($firstLine) >= 1) {
      if ($explode == "\n") {
        if ($firstLine[strlen($firstLine) - 1] == "\r") {
          $firstLine = substr($firstLine, 0, -1);
        }
      }
    }
    if (count($lines) > 1) {
      unset($lines[0]);
      $this->unparsedData = implode($explode, $lines);
      return $firstLine;
    } else {
      return null;
    }
  }

  /**
   * Replacement for default parse_str(), it supoorts UCS-2 like this: %uXXXX
   * @param  string  $s      String to parse
   * @param  array   &$var   Reference to the resulting array
   * @param  boolean $header Header-style string
   * @return void
   */
  public static function parse_str($s, &$var, $header = false) {
    static $cb;
    if ($cb === null) {
      $cb = function ($m) {
        return urlencode(html_entity_decode('&#' . hexdec($m[1]) . ';', ENT_NOQUOTES, 'utf-8'));
      };
    }
    if ($header) {
      $s = strtr($s, self::$hvaltr);
    }
    if (
      (stripos($s, '%u') !== false)
      && preg_match('~(%u[a-f\d]{4}|%[c-f][a-f\d](?!%[89a-f][a-f\d]))~is', $s, $m)
    ) {
      $s = preg_replace_callback('~%(u[a-f\d]{4}|[a-f\d]{2})~i', $cb, $s);
    }
    parse_str($s, $var);
  }

  /**
   * Convert bytes into integer
   * @param  string  $str Bytes
   * @param  boolean $l   Little endian? Default is false
   * @return integer
   */
  public static function bytes2int($str, $l = false) {
    if ($l) {
      $str = strrev($str);
    }
    $dec = 0;
    $len = strlen($str);
    for ($i = 0; $i < $len; ++$i) {
      $dec += ord(substr($str, $i, 1)) * pow(0x100, $len - $i - 1);
    }
    return $dec;
  }
}