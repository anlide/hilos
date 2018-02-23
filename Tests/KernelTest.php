<?php
use PHPUnit\Framework\TestCase;
use Hilos\App\Kernel;

class KernelTest extends TestCase
{
  public function testCreation()
  {
    $kernel = new Kernel();
    $this->assertInstanceOf(Kernel::class, $kernel);
  }
}