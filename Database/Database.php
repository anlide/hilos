<?php

namespace Hilos\Database;

/**
 * No any adapters. Only mysql was implemented.
 *
 * Class Database
 * @package Hilos\Database
 */
class Database {
  /** @var \mysqli */
  private static $connect;
  /** @var \mysqli_result */
  private static $result;

  private static $host;
  private static $user;
  private static $pass;
  private static $dbname;
  private static $port;

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
   * @throws \Exception
   */
  public static function configure(
    $host = '127.0.0.1', $user = 'root', $pass = '', $dbname = 'hilos', $port = '3306'
  ) {
    self::$host = $host;
    self::$user = $user;
    self::$pass = $pass;
    self::$dbname = $dbname;
    self::$port = $port;
    self::connect();
  }

  /**
   * @throws \Exception
   */
  private static function connect() {
    self::$connect = @mysqli_connect(self::$host, self::$user, self::$pass, self::$dbname, self::$port);
    if (!self::$connect){
      $error_text = 'Database not available ['.mysqli_connect_errno().'] '.mysqli_connect_error();
      throw new \Exception($error_text, mysqli_connect_errno());
    }
    self::sql('SET NAMES `utf8`;');
    self::sql('SET @@session.time_zone = "+00:00";');
  }

  /**
   * @param $sql
   * @param null $params
   * @param bool|true $try_reconnect
   * @throws \Exception
   */
  public static function sql($sql, $params = null, $try_reconnect = true) {
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
        } else {
          throw new \Exception('SQL parameter not a string or numeric or boolean', 500);
        }
        $newSql .= $notParams[$i+1];
      }
      $parsedSql = $newSql;
    } else {
      $parsedSql = $sql;
    }
    @mysqli_multi_query(self::$connect, $parsedSql);
    self::$result = self::$connect->store_result();
    $error = mysqli_error(self::$connect);
    if($error != ''){
      $errno = mysqli_errno(self::$connect);
      if ($errno == 2006) {
        if ($try_reconnect) {
          self::connect();
          self::sql($sql, $params, false);
        }
      } elseif (($errno == 1642) || ($errno == 1643) || ($errno == 1644)) {
        $tmp = explode('|', $error);
        throw new \Exception($tmp[0], $tmp[1]);
      } else {
        throw new \Exception($error.' sql ---'.$parsedSql.'---', $errno);
      }
    }
  }

  public static function nextRows() {
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

  public static function count() {
    if (self::$result === false) return false;
    return mysqli_num_rows(self::$result);
  }

  public static function lastInsertId() {
    return self::$connect->insert_id;
  }

  public static function row($sql, $params = null) {
    self::sql($sql, $params);
    return mysqli_fetch_assoc(self::$result);
  }

  public static function rows($sql, $params = null) {
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
  
  public static function field($sql, $params = null) {
    self::sql($sql, $params);
    if (self::$result === false) return array();
    $tmp = mysqli_fetch_array(self::$result);
    return $tmp[0];
  }
}