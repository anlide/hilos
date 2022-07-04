<?php

namespace Hilos\Daemon\Client;

use Exception;

/**
 * Class Client
 * @package Hilos\Daemon\Client
 */
abstract class Client implements IClient {
  protected static array $hvaltr = ['; ' => '&', ';' => '&', ' ' => '%20'];

  const STATE_STANDBY = 0;
  const WRITE_DELAY_TIMEOUT = 10;

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

  const MAX_BUFFER_SIZE = 1024 * 1024 * 32;

  protected string $extensionsCleanRegex = '/(?:^|\W)x-webkit-/iS';

  /** @var resource */
  protected $socket;
  protected bool $closed = false;
  protected int $state = self::STATE_STANDBY;
  protected string $unparsedData = '';
  protected ?int $closeStatus;

  /** @var array _SERVER */
  protected array $server = [];

  /** @var string[] */
  protected array $delayWrite = [];
  protected ?int $failedStart = null;

  function getSocket() {
    return $this->socket;
  }

  function getState(): int {
    return $this->state;
  }

  function getUnparsedData(): string {
    return $this->unparsedData;
  }

  function getServerKey($key) {
    return $this->server[$key];
  }

  function setServerKey($key, $value) {
    $this->server[$key] = $value;
  }

  function issetServerKey($key): bool {
    return isset($this->server[$key]);
  }

  public function close($reason = self::CLOSE_NO_STATUS) {
    if ($this->closed) return;
    $this->closeStatus = $reason;
    socket_close($this->socket);
    $this->closed = true;
  }

  public function closed(): bool {
    return $this->closed;
  }

  public function stop() {
    $this->close(self::CLOSE_NORMAL);
  }

  /**
   * @throws Exception
   */
  public function tick() {
    if (count($this->delayWrite) == 0) return;
    if ($this->failedStart === null) $this->failedStart = time();
    $delayWrite = $this->delayWrite;
    $this->delayWrite = [];
    $crashedState = false;
    foreach ($delayWrite as $data) {
      if ($crashedState) {
        $this->delayWrite[] = $data;
      } else {
        if ($this->write($data) === false) {
          $crashedState = true;
        }
      }
    }
    if (count($this->delayWrite) == 0) {
      $this->failedStart = null;
    } else {
      if (time() - $this->failedStart > self::WRITE_DELAY_TIMEOUT) {
        throw new Exception('Failed to write more than '.self::WRITE_DELAY_TIMEOUT.' seconds');
      }
    }
  }

  protected final function receiveData() {
    if ($this->closed) return;
    socket_clear_error($this->socket);
    $data = @socket_read($this->socket, self::MAX_BUFFER_SIZE);
    if (is_string($data) && !empty($data)) {
      $this->unparsedData .= $data;
    } else {
      if (in_array(socket_last_error(), array(32, 104, 10054))) {
        $this->close(self::CLOSE_GOING_AWAY);
      } else {
        $this->close(self::CLOSE_ABNORMAL);
      }
      return;
    }
  }

  /**
   * Searches first occurrence of the string in input buffer
   * @param  string  $what  Needle
   * @param  integer $start Offset start
   * @return integer|bool   Position
   */
  public function search(string $what, int $start = 0) {
    return strpos($this->unparsedData, $what, $start);
  }

  /**
   * Reads all data from the connection's buffer
   * @return string Readed data
   */
  public function readUnlimited(): string {
    $ret = $this->unparsedData;
    $this->unparsedData = '';
    return $ret;
  }

  /**
   * Read data from the connection's buffer
   * @param  integer      $n Max. number of bytes to read
   * @return string|false    Readed data
   */
  public function read(int $n) {
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
   * @return string|bool
   */
  public function drain(int $n) {
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
  public function look(int $n, int $o = 0) {
    if (strlen($this->unparsedData) <= $o) {
      return '';
    }
    return substr($this->unparsedData, $o, $n);
  }

  /**
   * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
   * @param string $data Data to send
   * @return bool|int
   * @throws Exception
   */
  public function write(string $data) {
    if ($this->closed) return false;
    if (count($this->delayWrite) != 0) {
      $this->delayWrite[] = $data;
      return false;
    }
    $bytesLeft = $total = strlen($data);
    $tryCount = 0;
    do {
      socket_clear_error($this->socket);
      $sended = @socket_write($this->socket, $data, 65536 - 1);
      if ($sended === false) {
        if ((socket_strerror(socket_last_error()) == 'Resource temporarily unavailable') || (socket_last_error() == 10035)) {
          $this->delayWrite[] = $data;
          return false;
        } else {
          if (socket_last_error() == 104) {
            $this->close(self::CLOSE_GOING_AWAY);
            return false;
          }
          if (socket_last_error() == 32) {
            $this->close(self::CLOSE_GOING_AWAY);
            return false;
          }
          if (socket_last_error() == 10053) {
            $this->close(self::CLOSE_GOING_AWAY);
            return false;
          }
          if (socket_last_error() == 10054) {
            $this->close(self::CLOSE_GOING_AWAY);
            return false;
          }
          error_log('Hilos socket write error [' . socket_last_error() . '] : ' . socket_strerror(socket_last_error()));
          $this->close(self::CLOSE_ABNORMAL);
          return false;
        }
      }
      $bytesLeft -= $sended;
      $data = substr($data, $sended);
      $tryCount++;
      if ($tryCount >= 100) {
        $this->delayWrite[] = $data;
        return false;
      }
    } while ($bytesLeft > 0);
    return $total;
  }

  /**
   * Send Bad request
   * @return void
   * @throws Exception
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
   * Replacement for default parse_str(), it supports UCS-2 like this: %uXXXX
   * @param  string  $s      String to parse
   * @param  array   &$var   Reference to the resulting array
   * @param  boolean $header Header-style string
   * @return void
   */
  public static function parse_str(string $s, array &$var, bool $header = false) {
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
  public static function bytes2int(string $str, bool $l = false): int {
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