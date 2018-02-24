<?php
use PHPUnit\Framework\TestCase;
use Hilos\Tests\Daemon\Master1;

class MasterTest extends TestCase
{
  public function testCreation() {
    $worker = new Master1();
    $this->assertInstanceOf(Master1::class, $worker);
  }
}