<?php
use PHPUnit\Framework\TestCase;
use Hilos\Tests\Daemon\Worker1;

class WorkerTest extends TestCase
{
  public function testCreation() {
    $worker = new Worker1();
    $this->assertInstanceOf(Worker1::class, $worker);
  }
}