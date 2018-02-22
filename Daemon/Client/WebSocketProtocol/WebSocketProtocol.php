<?php
namespace Hilos\Daemon\Client\WebSocketProtocol;

use Hilos\Daemon\Client\WebSocket;

abstract class WebSocketProtocol implements IWebSocketProtocol {
  /** @var WebSocket */
  protected $client;
  public function __construct(&$client) {
    $this->client = $client;
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