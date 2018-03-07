<?php

namespace Hilos\Daemon\Client;

use Hilos\Daemon\Client\WebSocketProtocol\IWebSocketProtocol;

class WebSocket extends Client {
  const STATE_FIRSTLINE  = 1;
  const STATE_HEADERS    = 2;
  const STATE_CONTENT    = 3;
  const STATE_PREHANDSHAKE = 5;
  const STATE_HANDSHAKED = 6;

  const OP_CONTINUE =  0;
  const OP_TEXT     =  1;
  const OP_BINARY   =  2;
  const OP_CLOSE    =  8;
  const OP_PING     =  9;
  const OP_PONG     = 10;

  const MAX_ALLOWED_PACKET = 1024 * 1024 * 8;

  private $extensions;
  private $currentHeader;
  /** @var IWebSocketProtocol */
  protected $protocol = null;

  protected $ip;
  protected $handshaked = false;

  function __construct($socket) {
    $this->socket = $socket;
    @socket_getpeername($socket, $this->ip);
  }

  public function getHandshaked() {
    return $this->handshaked;
  }

  public function getIp() {
    return $this->ip;
  }

  public function handle() {
    if ($this->closed) return;
    $this->receiveData();
    if ($this->protocol !== null) {
      $this->protocol->handle();
      return;
    }
    if ($this->state === self::STATE_STANDBY) {
      $this->state = self::STATE_FIRSTLINE;
    }
    if ($this->state === self::STATE_FIRSTLINE) {
      if (!$this->httpReadFirstLine()) {
        return;
      }
      $this->state = self::STATE_HEADERS;
    }

    if ($this->state === self::STATE_HEADERS) {
      if (!$this->httpReadHeaders()) {
        return;
      }
      if (!$this->httpProcessHeaders()) {
        $this->close(self::CLOSE_PROTOCOL);
        return;
      }
      $this->state = self::STATE_CONTENT;
    }
    if ($this->state === self::STATE_CONTENT) {
      $this->state = self::STATE_PREHANDSHAKE;
    }
    if ($this->protocol !== null) {
      $this->protocol->handle(); // TODO: Наверное не нужный код
    }
  }

  /**
   * This method should be overrided and used.
   *
   * @param $data
   * @param $type
   */
  public function onFrame($data, $type) {}

  /**
   * Called when we're going to handshake.
   * @return boolean Handshake status
   */
  public function handshake() {
    if (!$this->protocol->sendHandshakeReply()) {
      error_log(get_class($this) . '::' . __METHOD__ . ' : Handshake protocol failure for client "'.$this->ip.'"');
      $this->close(self::CLOSE_PROTOCOL);
      return false;
    }

    $this->handshaked = true;
    $this->state = static::STATE_HANDSHAKED;
    return true;
  }

  /**
   * Read first line of HTTP request
   * @return boolean|null Success
   */
  protected function httpReadFirstLine() {
    if (($l = $this->readLine(PHP_EOL)) === null) {
      return null;
    }
    $e = explode(' ', $l);
    $u = isset($e[1]) ? parse_url($e[1]) : false;
    if ($u === false) {
      $this->badRequest();
      return false;
    }
    if (!isset($u['path'])) {
      $u['path'] = null;
    }
    if (isset($u['host'])) {
      $this->server['HTTP_HOST'] = $u['host'];
    }
    //$address = explode(':', socket_get_name($this->socket, true)); //получаем адрес клиента
    $srv                       = & $this->server;
    $srv['REQUEST_METHOD']     = $e[0];
    $srv['REQUEST_TIME']       = time();
    $srv['REQUEST_TIME_FLOAT'] = microtime(true);
    $srv['REQUEST_URI']        = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
    $srv['DOCUMENT_URI']       = $u['path'];
    $srv['PHP_SELF']           = $u['path'];
    $srv['QUERY_STRING']       = isset($u['query']) ? $u['query'] : null;
    $srv['SCRIPT_NAME']        = $srv['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
    $srv['SERVER_PROTOCOL']    = isset($e[2]) ? $e[2] : 'HTTP/1.1';
    $srv['REMOTE_ADDR']        = null; //$address[0];
    $srv['REMOTE_PORT']        = null; //$address[1];
    return true;
  }
  /**
   * Read headers line-by-line
   * @return boolean|null Success
   */
  protected function httpReadHeaders() {
    while (($l = $this->readLine(PHP_EOL)) !== null) {
      if ($l === '') {
        return true;
      }
      $e = explode(': ', $l);
      if (isset($e[1])) {
        $this->currentHeader                = 'HTTP_' . strtoupper(strtr($e[0], ['-' => '_']));
        $this->server[$this->currentHeader] = $e[1];
      } elseif (($e[0][0] === "\t" || $e[0][0] === "\x20") && $this->currentHeader) {
        // multiline header continued
        $this->server[$this->currentHeader] .= $e[0];
      } else {
        // whatever client speaks is not HTTP anymore
        $this->badRequest();
        return false;
      }
    }
    return false;
  }
  /**
   * Process headers
   * @return bool
   */
  protected function httpProcessHeaders() {
    $this->state = self::STATE_PREHANDSHAKE;
    if (isset($this->server['HTTP_X_REAL_IP'])) {
      $this->ip = $this->server['HTTP_X_REAL_IP'];
    } elseif (isset($this->server['HTTP_X_FORWARDED_FOR'])) {
      $this->ip = $this->server['HTTP_X_FORWARDED_FOR'];
    }
    if (isset($this->server['HTTP_SEC_WEBSOCKET_EXTENSIONS'])) {
      $str              = strtolower($this->server['HTTP_SEC_WEBSOCKET_EXTENSIONS']);
      $str              = preg_replace($this->extensionsCleanRegex, '', $str);
      $this->extensions = explode(', ', $str);
    }
    if (!isset($this->server['HTTP_CONNECTION'])
      || (!preg_match('~(?:^|\W)Upgrade(?:\W|$)~i', $this->server['HTTP_CONNECTION'])) // "Upgrade" is not always alone (ie. "Connection: Keep-alive, Upgrade")
      || !isset($this->server['HTTP_UPGRADE'])
      || (strtolower($this->server['HTTP_UPGRADE']) !== 'websocket') // Lowercase comparison iss important
    ) {
      $this->close(self::CLOSE_PROTOCOL);
      return false;
    }
    //if (isset($this->server['HTTP_COOKIE'])) {
      //self::parse_str(strtr($this->server['HTTP_COOKIE'], self::$hvaltr), $this->cookie);
    //}
    //if (isset($this->server['QUERY_STRING'])) {
      //self::parse_str($this->server['QUERY_STRING'], $this->get);
    //}
    // ----------------------------------------------------------
    // Protocol discovery, based on HTTP headers...
    // ----------------------------------------------------------
    if (isset($this->server['HTTP_SEC_WEBSOCKET_VERSION'])) { // HYBI
      if ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] === '8') { // Version 8 (FF7, Chrome14)
        $this->useProtocol('V13');
      } elseif ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] === '13') { // newest protocol
        $this->useProtocol('V13');
      } else {
        error_log(get_class($this) . '::' . __METHOD__ . " : Websocket protocol version " . $this->server['HTTP_SEC_WEBSOCKET_VERSION'] . ' is not yet supported for client "addr"');
        $this->close(self::CLOSE_PROTOCOL);
        return false;
      }
    } elseif (!isset($this->server['HTTP_SEC_WEBSOCKET_KEY1']) || !isset($this->server['HTTP_SEC_WEBSOCKET_KEY2'])) {
      $this->useProtocol('Ve');
    } else { // Defaulting to HIXIE (Safari5 and many non-browser clients...)
      $this->useProtocol('V0');
    }
    // ----------------------------------------------------------
    // End of protocol discovery
    // ----------------------------------------------------------
    return true;
  }

  protected function useProtocol($protocol) {
    $class = 'Hilos\\Daemon\\Client\\WebSocketProtocol\\'.$protocol;
    $this->protocol = new $class($this, $this->unparsedData);
  }

  public function sendFrame($data, $type = null) {
    $this->protocol->sendFrame($data, $type);
  }
}