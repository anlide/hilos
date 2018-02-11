<?php
namespace Hilos\Deamon\Socket;

class Connection {
  protected static $hvaltr = ['; ' => '&', ';' => '&', ' ' => '%20'];

  const MAX_ALLOWED_PACKET = 1024 * 1024 * 8;
  const MAX_BUFFER_SIZE = 1024 * 1024;

  protected $indexSocket;
  protected $socket;
  protected $ip;
  protected $custom;

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
  const CLOSE_SESSION_INVALID = 1013;
  const CLOSE_SESSION_CORRUPTED = 1014;
  const CLOSE_TLS         = 1015;

  public $closeStatus = null;
  private $closeAbnormalTries = 0;

  public static function closeReasonToString($reason) {
    switch ($reason) {
      case self::CLOSE_NORMAL:
        return 'normal';
      case self::CLOSE_GOING_AWAY:
        return 'going away';
      case self::CLOSE_PROTOCOL:
        return 'protocol';
      case self::CLOSE_BAD_DATA:
        return 'bad data';
      case self::CLOSE_NO_STATUS:
        return 'no status';
      case self::CLOSE_ABNORMAL:
        return 'abnormal';
      case self::CLOSE_BAD_PAYLOAD:
        return 'bad payload';
      case self::CLOSE_POLICY:
        return 'policy';
      case self::CLOSE_TOO_BIG:
        return 'too big';
      case self::CLOSE_MAND_EXT:
        return 'mand ext';
      case self::CLOSE_SRV_ERR:
        return 'srv err';
      case self::CLOSE_SESSION:
        return 'session';
      case self::CLOSE_SESSION_INVALID:
        return 'session invalid';
      case self::CLOSE_SESSION_CORRUPTED:
        return 'session corrupted';
      case self::CLOSE_TLS:
        return 'tls';
      default:
        return 'unknown';
    }
  }
  /**
   * @var array _SERVER
   */
  public $server = [];

  /**
   * @var array _COOKIE
   */
  public $cookie = [];

  /**
   * @var array _GET
   */
  //public $get = [];

  protected $handshaked = false;

  protected $headers = [];
  protected $headers_sent = false;

  protected $closed = false;
  protected $unparsedData = '';
  /**
   * @var Connection|null
   */
  protected $newInstance = null;

  protected $extensions = [];
  protected $extensionsCleanRegex = '/(?:^|\W)x-webkit-/iS';

  /**
   * @var integer Current state
   */
  protected $state = 0; // stream state of the connection (application protocol level)

  /**
   * Alias of STATE_STANDBY
   */
  const STATE_ROOT = 0;

  /**
   * Standby state (default state)
   */
  const STATE_STANDBY = 0;

  public function getHeaders() {
    return $this->server;
  }

  public function getState() {
    return $this->state;
  }

  public function getNewInstance() {
    return $this->newInstance;
  }

  public function getIndexSocket() {
    return $this->indexSocket;
  }

  public function closed() {
    return $this->closed;
  }

  public function close($reason = self::CLOSE_NO_STATUS) {
    if ($this->closed) return;
    $this->closeStatus = $reason;
    socket_close($this->socket);
    $this->closed = true;
  }
  public function __construct($socket, $indexSocket, $ip = null, $custom = null) {
    socket_set_nonblock($socket);
    $this->socket = $socket;
    $this->indexSocket = $indexSocket;
    $this->ip = $ip;
    $this->custom = $custom;
  }

  public function getCustom() {
    return $this->custom;
  }

  public function getIp() {
    return $this->ip;
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

  public final function onReceiveData() {
    if ($this->closed) return;
    socket_clear_error($this->socket);
    $data = socket_read($this->socket, self::MAX_BUFFER_SIZE);
    if (is_string($data) && !empty($data)) {
      $this->unparsedData .= $data;
      $this->closeAbnormalTries = 0;
    } elseif ($this instanceof Websocket) {
      if (in_array(socket_last_error(), array(32, 104))) {
        $this->close(self::CLOSE_GOING_AWAY);
      } else {
        if ($this->closeAbnormalTries > 1000) {
          $this->close(self::CLOSE_ABNORMAL);
        }
        $this->closeAbnormalTries++;
      }
      return;
    }
  }
  /**
   * Called when new data received.
   * @return boolean
   */
  public function onRead() {
    return (!$this->closed);
  }
  /**
   * Send Bad request
   * @return void
   */
  public function badRequest() {
    $this->write("400 Bad Request\r\n\r\n<html><head><title>400 Bad Request</title></head><body bgcolor=\"white\"><center><h1>400 Bad Request</h1></center></body></html>");
    $this->close(self::CLOSE_BAD_DATA);
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
          throw new \Exception( sprintf( "Unable to write to socket: %s", socket_strerror( socket_last_error() ) ).'|'.socket_last_error().'|' );
        }
      }
      $bytesLeft -= $sended;
      $data = substr($data, $sended);
      $tryCount++;
      if ($tryCount >= 100) throw new \Exception('socket_write more then ' . $tryCount . ' times!');
    } while ($bytesLeft > 0);
    return $total;
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
   * Reads all data from the connection's buffer
   * @return string Readed data
   */
  public function readUnlimited() {
    $ret = $this->unparsedData;
    $this->unparsedData = '';
    return $ret;
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
   * Called when new frame received.
   * @param  string $data Frame's data.
   * @param  string $type Frame's type ("STRING" OR "BINARY").
   * @return boolean      Success.
   */
  public function onFrame($data, $type) {
    return true;
  }
  public function sendFrame($data, $type = null, $cb = null) {
    return false;
  }
  /**
   * Get real frame type identificator
   * @param $type
   * @return integer
   */
  public function getFrameType($type) {
    if (is_int($type)) {
      return $type;
    }
    if ($type === null) {
      $type = 'STRING';
    }
    $frameType = @constant(get_class($this) . '::' . $type);
    if ($frameType === null) {
      error_log(__METHOD__ . ' : Undefined frameType "' . $type . '"');
    }
    return $frameType;
  }
}
