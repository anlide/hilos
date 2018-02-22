<?php
namespace Hilos\Daemon;

use Hilos\Daemon\Exception\ConnectionTimeout;
use Hilos\Daemon\Exception\InvalidInternalConnection;
use Hilos\Daemon\Exception\NonJsonResponse;
use Hilos\Daemon\Exception\SocketSelect;

class Client {

  private function __construct() {}
  private function __clone() {}

  /**
   * @param string $method
   * @param mixed $data
   * @return array|null
   * @throws ConnectionTimeout
   * @throws InvalidInternalConnection
   * @throws NonJsonResponse
   * @throws SocketSelect
   */
  public static function sendInternalRequest($method, $data) {
    $socket = @stream_socket_client('127.0.0.1:'.'8206', $errno, $errstr, 15);
    if (!$socket) {
      throw new InvalidInternalConnection();
    }
    stream_socket_sendto($socket, json_encode(array('method' => $method, 'data' => $data)).PHP_EOL);
    $timeLeft = time();
    while (time() - $timeLeft < 2) {
      $read = array($socket);
      $write = $except = array();
      if (stream_select($read, $write, $except, 1) === false) {
        throw new SocketSelect();
      }
      if (!empty($read)) {
        $json = null;
        $ret = '';
        do {
          $data = stream_socket_recvfrom($socket, 1024*1024);
          if ($data == '') break;
          $ret .= $data;
          $json = json_decode($ret, true);
        } while ($json === null);
        fclose($socket);
        if ($json === null) {
          throw new NonJsonResponse($ret);
        }
        return $json;
      }
    }
    fclose($socket);
    throw new ConnectionTimeout();
  }
}
