<?php
namespace Hilos\Service;

class Config {
  public static function env($key, $defaultValue = null) {
    $fileEnv = self::root() . '/.env';
    if (file_exists($fileEnv)) {
      $content = file_get_contents($fileEnv);
      $lines = explode("\n", $content);
      foreach ($lines as $line) {
        $tmp = explode('=', trim($line));
        if (!isset($tmp[1])) continue;
        $lineKey = trim($tmp[0]);
        $lineValue = trim($tmp[1]);
        if ($lineKey == $key) {
          return strtolower($lineValue) == 'null' ? null : $lineValue;
        }
      }
    }
    return $defaultValue;
  }
  public static function root() {
    return __DIR__ . '/../../../../';
  }
}