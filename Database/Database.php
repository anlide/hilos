<?php

namespace Hilos\Database;

use Hilos\Service\Config;
use Hilos\Database\Exception\Sql;
use mysqli;
use mysqli_result;

/**
 * No any adapters. Only mysql was implemented.
 *
 * Class Database
 * @package Hilos\Database
 */
class Database {
  /** @var mysqli */
  private static mysqli $connect;

  /** @var mysqli_result|bool */
  private static $result;

  /** @var boolean */
  public static bool $debug = false;

  private static string $host;
  private static string $user;
  private static string $pass;
  private static string $dbname;
  private static string $port;

  private function __clone(){}
  private function __construct(){}
  private function __destruct() {
    if (self::$connect) mysqli_close(self::$connect);
  }

  /**
   * @param string $host
   * @param string $user
   * @param string $pass
   * @param string $dbname
   * @param string $port
   * @throws Sql
   */
  public static function configure(
    string $host = 'localhost', string $user = 'root', string $pass = '', string $dbname = 'hilos', string $port = '3306'
  ): void
  {
    self::$host = $host;
    self::$user = $user;
    self::$pass = $pass;
    self::$dbname = $dbname;
    self::$port = $port;
    self::connect();
  }

  /**
   * @throws Sql
   */
  private static function connect(): void
  {
    self::$connect = @mysqli_connect(self::$host, self::$user, self::$pass, self::$dbname, self::$port);
    if (!self::$connect){
      $error_text = 'Database not available ['.mysqli_connect_errno().'] '.mysqli_connect_error();
      throw new Sql($error_text, mysqli_connect_errno());
    }
    if (mysqli_connect_errno()) {
      $error_text = 'Database connection error ['.mysqli_connect_errno().'] '.mysqli_connect_error();
      throw new Sql($error_text, mysqli_connect_errno());
    }
    self::sql('SET NAMES `utf8`;');
    self::sql('SET @@session.time_zone = "+00:00";');
  }

  /**
   * @param $sql
   * @param null $params
   * @param bool $try_reconnect
   * @throws Sql
   */
  public static function sql($sql, $params = null, bool $try_reconnect = true): void
  {
    while (@self::$connect->next_result()) self::$connect->store_result();
    if ($params !== null) {
      if (!is_array($params)) $params = array($params);
      $notParams = explode('?', $sql);
      $newSql = $notParams[0];
      for ($i = 0; $i < count($notParams) - 1; $i++) {
        $param = $params[$i];
        if ($param === null) {
          $newSql .= 'NULL';
        } elseif (is_numeric($param)) {
          $newSql .= $param;
        } elseif (is_bool($param)) {
          $newSql .= ($param?'true':'false');
        } elseif (is_string($param)) {
          $newSql .= '"'.str_replace('"', '\"', $param).'"';
        } elseif (is_array($param)) {
          $newParams = [];
          foreach ($param as $subParam) {
            if ($subParam === null) {
              $newParams[] = 'NULL';
            } elseif (is_numeric($subParam)) {
              $newParams[] = $subParam;
            } elseif (is_bool($subParam)) {
              $newParams[] = ($subParam?'true':'false');
            } elseif (is_string($subParam)) {
              $newParams[] = '"'.str_replace('"', '\"', $subParam).'"';
            } else {
              throw new Sql('SQL parameter not a string or numeric or boolean', 500);
            }
          }
          $newSql .= implode(',', $newParams);
        } else {
          throw new Sql('SQL parameter not a string or numeric or boolean', 500);
        }
        $newSql .= $notParams[$i+1];
      }
      $parsedSql = $newSql;
    } else {
      $parsedSql = $sql;
    }
    if (self::$debug) {
      $file = 'sql.debug.log';
      $fp = fopen($file, 'a+');
      list($usec, $sec) = explode(" ", microtime());
      $logString = date("Y-m-d H:i:s", $sec).'.'.str_pad(floor($usec*1000), 3, '0').': '.$parsedSql;
      fwrite($fp, $logString."\r\n");
      fclose($fp);
      error_log($logString);
    }
    try {
      @mysqli_multi_query(self::$connect, $parsedSql);
      self::$result = self::$connect->store_result();
      $error = '';
    } catch (Sql $exception) {
      $error = mysqli_error(self::$connect);
    }
    if($error != ''){
      $errno = mysqli_errno(self::$connect);
      if ($errno == 2006) {
        if ($try_reconnect) {
          $attempt = 0;
          do {
            try {
              $attempt++;
              self::connect();
            } catch (Sql $e) {
              error_log('Database connection error ['.mysqli_connect_errno().'] '.mysqli_connect_error());
              $sleepTime = intval(Config::env('HILOS_DATABASE_RECONNECT_SLEEP', 5));
              if ($sleepTime < 1) $sleepTime = 1;
              error_log('Mysql reconnect attempt #'.$attempt.' sleep '.$sleepTime.' seconds');
              sleep($sleepTime);
            }
          } while (
            (mysqli_connect_errno() !== 0) &&
            (filter_var(Config::env('HILOS_DATABASE_RECONNECT_2006', false), FILTER_VALIDATE_BOOLEAN))
          );
          error_log('Mysql reconnected');
          self::sql($sql, $params, filter_var(Config::env('HILOS_DATABASE_RECONNECT_2006', false), FILTER_VALIDATE_BOOLEAN));
        } else {
          throw new Sql('Mysql server has gone away, and give up to to reconnect!');
        }
      } elseif (($errno == 1642) || ($errno == 1643) || ($errno == 1644)) {
        $tmp = explode('|', $error);
        throw new Sql($tmp[0], $tmp[1]);
      } else {
        throw new Sql($error.' sql ---'.$parsedSql.'---', $errno);
      }
    }
  }

  /**
   * @param $sql
   * @param null $params
   * @param bool $try_reconnect
   * @throws Sql
   */
  public static function sqlRun($sql, $params = null, bool $try_reconnect = true): void
  {
    self::sql($sql, $params, $try_reconnect);
    $step = 0;
    do {
      if (!self::$connect->more_results()) {
        break;
      }
      if (!self::$connect->next_result()) {
        throw new Sql('mysqli_multi_query with was execute with error at step statement [#'.$step.'/'.self::$connect->error.']');
      }
      $step++;
    } while (true);
  }

  /**
   * @return array|false
   */
  public static function nextRows(): bool|array
  {
    self::$connect->next_result();
    self::$result = self::$connect->store_result();
    if (self::$result === false) return false;
    $row = true;
    $rows = array();
    while ($row) {
      $row = mysqli_fetch_assoc(self::$result);
      if (!$row) break;
      $rows[] = $row;
    }
    return $rows;
  }

  public static function count(): bool|int|string
  {
    if (self::$result === false) return false;
    return mysqli_num_rows(self::$result);
  }

  public static function lastInsertId(): int|string
  {
    return self::$connect->insert_id;
  }

  /**
   * @param $sql
   * @param null $params
   * @return array|null
   * @throws Sql
   */
  public static function row($sql, $params = null): ?array {
    self::sql($sql, $params);
    return mysqli_fetch_assoc(self::$result);
  }

  /**
   * @param $sql
   * @param null $params
   * @return array
   * @throws Sql
   */
  public static function rows($sql, $params = null): array {
    self::sql($sql, $params);
    $row = true;
    $rows = array();
    while ($row) {
      $row = mysqli_fetch_assoc(self::$result);
      if (!$row) break;
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * @param $sql
   * @param null $params
   * @return array|false|null
   * @throws Sql
   */
  public static function field($sql, $params = null): bool|array|null
  {
    self::sql($sql, $params);
    if (self::$result === false) return array();
    $tmp = mysqli_fetch_array(self::$result);
    return $tmp[0];
  }
}