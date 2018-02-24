<?php
use PHPUnit\Framework\TestCase;
use Hilos\Service\Crypt;

class CryptTest extends TestCase
{
  public function testCreation() {
    $string = 'Some value';
    $key = 'Some key';
    $encryptedString = Crypt::encrypt($string, $key);
    $this->assertEquals($string, Crypt::decrypt($encryptedString, $key));
  }
}