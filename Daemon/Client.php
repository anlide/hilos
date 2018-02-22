<?php
namespace Hilos\Daemon;

class Client {

  private function __construct() {}
  private function __clone() {}

  /**
   * @param string $method
   * @param mixed $data
   * @return array|null
   * @throws \Exception
   */
  public static function sendInternalRequest($method, $data) {
    $socket = @stream_socket_client('127.0.0.1:'.'8206', $errno, $errstr, 15);
    if (!$socket) {
      throw new \Exception('Invalid internal connection');
    }
    stream_socket_sendto($socket, json_encode(array('method' => $method, 'data' => $data)).PHP_EOL);
    $timeLeft = time();
    while (time() - $timeLeft < 2) {
      $read = array($socket);
      $write = $except = array();
      if (stream_select($read, $write, $except, 1) === false) {
        throw new \Exception('stream_select error');
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
          throw new \Exception('Non-json response: '.$ret);
        }
        return $json;
      }
    }
    fclose($socket);
    throw new \Exception('Connection timeout');
  }
}
