<?php
use PHPUnit\Framework\TestCase;
use Hilos\Daemon\Client;

class ClientTest extends TestCase {
  private function execInBackground($cmd) {
    if (substr(php_uname(), 0, 7) == "Windows"){
      pclose(popen("start /B ". $cmd, "r"));
    }
    else {
      exec($cmd . " > /dev/null &");
    }
  }

  public function testRequest() {
    $this->execInBackground('php ' . __DIR__ . '\Daemon\Master1Start.php');
    $data = ['test' => 'test'];
    $method = 'test';
    $ret = Client::sendInternalRequest($method, $data, 8207);
    $this->assertEquals($method, $ret['method']);
    $this->assertEquals($data, $ret['data']);
  }
}