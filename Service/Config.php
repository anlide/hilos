<?php
namespace Hilos\Service;

class Config {
  public static function env($key) {
    $fileEnv = __DIR__ . '/../../../../.env';
    if (file_exists($fileEnv)) {
      $content = file_get_contents($fileEnv);
      $lines = explode("\n", $content);
      foreach ($lines as $line) {
        $tmp = explode('=', trim($line));
        $lineKey = trim($tmp[0]);
        $lineValue = trim($tmp[1]);
        if ($lineKey == $key) {
          return $lineValue;
        }
      }
    }
    return null;
  }
}