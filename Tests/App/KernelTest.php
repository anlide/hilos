<?php
use PHPUnit\Framework\TestCase;
use Hilos\Tests\App\Kernel1;
use Hilos\Tests\App\Kernel2;
use Hilos\Tests\App\Kernel3;
use Hilos\App\Exception\RouteInvalid;
use Hilos\App\Exception\NoRouteForRequest;
use Hilos\Tests\App\Exception\Custom;

class KernelTest extends TestCase
{
  public function testCreation() {
    $kernel = new Kernel1();
    $this->assertInstanceOf(Kernel1::class, $kernel);
  }
  public function testEmptyRoute() {
    $this->expectException(NoRouteForRequest::class);
    $_SERVER['REQUEST_URI'] = '/';
    $kernel = new Kernel1();
    $kernel->handle();
  }
  public function testInvalidRoute() {
    $this->expectException(RouteInvalid::class);
    $_SERVER['REQUEST_URI'] = '/wrong';
    $kernel = new Kernel2();
    $kernel->handle();
  }
  public function testFollowException() {
    $this->expectException(Custom::class);
    $_SERVER['REQUEST_URI'] = '/exception';
    $kernel = new Kernel3();
    $kernel->handle();
  }
  public function testRouteEqual() {
    $_SERVER['REQUEST_URI'] = '/test';
    $kernel = new Kernel1();
    $kernel->handle();
    $this->assertEquals($kernel, 'content');
  }
  public function testRoutePregMatch1() {
    $_SERVER['REQUEST_URI'] = '/test=match';
    $kernel = new Kernel1();
    $kernel->handle();
    $this->assertEquals($kernel, 'content-match');
  }
  public function testRoutePregMatch2() {
    $this->expectException(NoRouteForRequest::class);
    $_SERVER['REQUEST_URI'] = '/test-wrong';
    $kernel = new Kernel1();
    $kernel->handle();
  }
}