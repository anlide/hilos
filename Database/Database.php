<?php

namespace Hilos\Database;

use Hilos\Database\Exception\SqlTimeout;
use Hilos\Service\Config;
use Hilos\Database\Exception\Sql;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;

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
  private static string $sqlInit;

  private function __clone(){}
  private function __construct(){}
  private function __destruct() {
    if (self::$connect) mysqli_close(self::$connect);
  }

  public const LOCK_TYPE_READ = 'READ';
  public const LOCK_TYPE_READ_LOCAL = 'READ LOCAL';
  public const LOCK_TYPE_WRITE = 'WRITE';
  public const LOCK_TYPE_LOW_PRIORITY_WRITE = 'LOW_PRIORITY WRITE';
  public const LOCK_TYPES = [
    self::LOCK_TYPE_READ,
    self::LOCK_TYPE_READ_LOCAL,
    self::LOCK_TYPE_WRITE,
    self::LOCK_TYPE_LOW_PRIORITY_WRITE
  ];

  public const LOCK_TABLE_PARAM_TABLE = 'table';
  public const LOCK_TABLE_PARAM_DATABASE = 'database';
  public const LOCK_TABLE_PARAM_TYPE = 'type';

  /**
   * @param string $host
   * @param string $user
   * @param string $pass
   * @param string $dbname
   * @param string $port
   * @param string|null $sqlInit
   * @throws Sql
   */
  public static function configure(
    string $host = 'localhost',
    string $user = 'root',
    string $pass = '',
    string $dbname = 'hilos',
    string $port = '3306',
    ?string $sqlInit = null,
  ): void
  {
    self::$host = $host;
    self::$user = $user;
    self::$pass = $pass;
    self::$dbname = $dbname;
    self::$port = $port;
    if ($sqlInit === null) {
      self::$sqlInit = 'SET NAMES `utf8`; SET @@session.time_zone = "+00:00"';
    } else {
      self::$sqlInit = $sqlInit;
    }
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
    if (self::$sqlInit !== null) {
      $sqlParts = explode(';', self::$sqlInit);
      foreach ($sqlParts as $sqlPart) {
        if (trim($sqlPart) === '') continue;
        self::sql($sqlPart.';');
      }
    }
  }

  /**
   * @param $sql
   * @param null $params
   * @param bool $try_reconnect
   * @throws Sql
   * @throws SqlTimeout
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
    } catch (mysqli_sql_exception $e) {
      $error = $e->getMessage();
    } finally {
      $error = ($error === '') ? mysqli_error(self::$connect) : $error;
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
            } catch (\Exception $e) {
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
      } elseif (str_contains($error, 'max_statement_time exceeded')) {
        throw new SqlTimeout($error);
      } else {
        throw new Sql($error.' sql ---'.$parsedSql.'---', $errno);
      }
    }
  }

  /**
   * @param $sql
   * @param null $params
   * @param bool $try_reconnect
   * @param int|null $timeout
   * @throws Sql
   * @throws SqlTimeout
   */
  public static function sqlRun($sql, $params = null, bool $try_reconnect = true, ?int $timeout = null): void
  {
    if (!is_null($timeout)) {
      $result = self::$connect->query('SELECT @@max_statement_time as max_statement_time;');
      $row = $result->fetch_assoc();
      $originalMaxStatementTime = $row['max_statement_time'];
      unset($row, $result);
      self::$connect->query('SET SESSION max_statement_time = ' . ($timeout) . ';');
    }
    try {
      self::sql($sql, $params, $try_reconnect);
    } finally {
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

      if (!is_null($timeout)) {
        self::$connect->query('SET SESSION max_statement_time = ' . $originalMaxStatementTime . ';');
      }
    }
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

  public static function affectedRows(): int|string
  {
    return self::$connect->affected_rows;
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
   * @return array|bool
   * @throws Sql
   */
  public static function rows($sql, $params = null): array|bool {
    self::sql($sql, $params);
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

  /**
   * @param $sql
   * @param null $params
   * @return bool|int|string|array|null
   * @throws Sql
   */
  public static function field($sql, $params = null): bool|int|string|array|null
  {
    self::sql($sql, $params);
    if (self::$result === false) return array();
    $tmp = mysqli_fetch_array(self::$result);
    return $tmp[0];
  }

  /**
   * @return void
   * @throws Sql
   */
  public static function transactionStart(): void
  {
    self::sql('START TRANSACTION;');
  }

  /**
   * @return void
   * @throws Sql
   */
  public static function transactionCommit(): void
  {
    self::sql('COMMIT;');
  }

  /**
   * @return void
   * @throws Sql
   */
  public static function transactionRollback(): void
  {
    self::sql('ROLLBACK;');
  }

  /**
   * Lock the tables with specified lock types.
   *
   * @param array $tables Array of arrays, each inner array should have 'table' key for the table name
   *                      and optionally 'type' key for the lock type and 'database' key for the database name.
   *
   * @return void
   * @throws Sql If the input is invalid.
   */
  public static function lockTables(array $tables): void
  {
    if (empty($tables)) {
      throw new Sql('The array of tables cannot be empty.');
    }

    $lockParts = [];

    foreach ($tables as $tableInfo) {
      if (
        !is_array($tableInfo) ||
        !isset($tableInfo[self::LOCK_TABLE_PARAM_TABLE]) ||
        !is_string($tableInfo[self::LOCK_TABLE_PARAM_TABLE]) ||
        trim($tableInfo[self::LOCK_TABLE_PARAM_TABLE]) === ''
      ) {
        throw new Sql('Each table information must be an array with a non-empty string "table" key.');
      }

      $tableName = '`' . $tableInfo[self::LOCK_TABLE_PARAM_TABLE] . '`';

      if (
        isset($tableInfo[self::LOCK_TABLE_PARAM_DATABASE]) &&
        is_string($tableInfo[self::LOCK_TABLE_PARAM_DATABASE]) &&
        trim($tableInfo[self::LOCK_TABLE_PARAM_DATABASE]) !== ''
      ) {
        $databaseName = '`' . $tableInfo[self::LOCK_TABLE_PARAM_DATABASE] . '`.';
        $tableName = $databaseName . $tableName;
      }

      $lockType = isset($tableInfo[self::LOCK_TABLE_PARAM_TYPE]) && in_array(strtoupper($tableInfo[self::LOCK_TABLE_PARAM_TYPE]), self::LOCK_TYPES) ?
        strtoupper($tableInfo[self::LOCK_TABLE_PARAM_TYPE]) :
        self::LOCK_TYPE_READ;

      $lockParts[] = $tableName . ' ' . $lockType;
    }

    $sql = 'LOCK TABLES ' . implode(', ', $lockParts) . ';';
    self::sql($sql);
  }

  /**
   * @return void
   * @throws Sql
   */
  public static function unlockTables(): void
  {
    self::sql('UNLOCK TABLES;');
  }
}
