<?php

namespace Hilos\Database;

use Hilos\Database\Exception\Sql;
use Hilos\Service\Config;

/**
 * Migration system.
 *
 * Class Database
 * @package Hilos\Database
 */
class Migration {
  private static string $migrationListPath = 'data/migrations';
  private static string $migrationName = 'main';

  /**
   * @throws Sql
   */
  public static function up(): void
  {
    print('Start migration ['.self::$migrationName.']'."\n");
    $migrationList = self::getMigrationList();
    self::filterPassedList($migrationList);
    self::passMigration($migrationList);
  }

  /**
   * @throws Sql
   */
  public static function down(): void
  {
    print('Start migration down ['.self::$migrationName.']'."\n");
    $migrationList = self::getMigrationList();
    $indexMax = self::getMaxPassedIndex();
    krsort($migrationList);
    self::rollback($indexMax, $migrationList);
  }

  /**
   * @param $path
   * @return void
   */
  public static function setMigrationListPath($path): void
  {
    self::$migrationListPath = $path;
  }

  public static function setMigrationName($name): void
  {
    self::$migrationName = $name;
  }

  /**
   * @return array
   */
  private static function getMigrationList(): array {
    $list = [];
    $path = Config::root() . self::$migrationListPath;
    $files = scandir($path);
    foreach ($files as $file) {
      if ($file == '.') continue;
      if ($file == '..') continue;
      if (preg_match('~(\d+)migration\-up\.sql~', $file, $m)) {
        $index = $m[1];
        $list[$index] = $index;
      }
    }
    return $list;
  }

  /**
   * @throws Sql
   */
  private static function createMigrationTable(): void
  {
    $row = Database::row("SHOW TABLES LIKE 'migration';");
    if ($row === null) {
      Database::sqlRun('CREATE TABLE `migration` (
  `index` int(10) UNSIGNED NOT NULL,
  `failed` tinyint(1) NOT NULL DEFAULT \'1\'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
      Database::sqlRun('ALTER TABLE `migration` ADD PRIMARY KEY(`index`);');
    }
  }

  /**
   * @param $list
   * @throws Sql
   */
  private static function filterPassedList(&$list): void
  {
    self::createMigrationTable();
    $passedMigrations = Database::rows('SELECT * FROM `migration`;');
    foreach ($passedMigrations as $passedMigration) {
      if ($passedMigration['failed']) {
        throw new Sql('We have migration #' . $passedMigration['index'] . ' in failed state.');
      }
      $index = str_pad($passedMigration['index'], 3, '0', STR_PAD_LEFT);
      unset($list[$index]);
    }
  }

  /**
   * @return bool|int|string|array|null
   * @throws Sql
   */
  private static function getMaxPassedIndex(): bool|int|string|array|null {
    self::createMigrationTable();
    $countFailed = Database::field('SELECT COUNT(*) AS `count` FROM `migration` WHERE `failed` = true;');
    if ($countFailed > 0) {
      throw new Sql('We have '.$countFailed.' migrations in failed state.');
    }
    return Database::field('SELECT MAX(`index`) AS `max` FROM `migration`;');
  }

  /**
   * @param $list
   * @throws Sql
   */
  private static function passMigration($list): void
  {
    $path = Config::root() . self::$migrationListPath;
    foreach ($list as $index) {
      $fileName = $index.'migration-up.sql';
      $content = file_get_contents($path.'/'.$fileName);
      print('Run migration #'.$index.' ...');
      Database::sqlRun("INSERT INTO `migration` (`index`, `failed`) VALUES (?, true);", [intval($index)]);
      self::runSqlWithDelimiter($content);
      Database::sqlRun('UPDATE `migration` SET `failed` = false WHERE `index` = ?;', [intval($index)]);
      print(' done'."\n");
    }
  }

  /**
   * @param $index
   * @param $migrationList
   * @throws Sql
   */
  private static function rollback($index, $migrationList): void
  {
    $index = str_pad($index, 3, '0', STR_PAD_LEFT);
    if (!isset($migrationList[$index])) {
      throw new Sql('Wrong rollback operation for migration #'.$index);
    }
    $path = Config::root() . self::$migrationListPath;
    $fileName = $index.'migration-down.sql';
    $content = file_get_contents($path.'/'.$fileName);
    print('Rollback migration #'.$index.' ...');
    Database::sqlRun('UPDATE `migration` SET `failed` = true WHERE `index` = ?;', [intval($index)]);
    self::runSqlWithDelimiter($content);
    Database::sqlRun('DELETE FROM `migration` WHERE `index` = ?;', [intval($index)]);
    print(' done'."\n");
  }

  /**
   * @param $content
   * @return void
   * @throws Sql
   */
  private static function runSqlWithDelimiter($content): void
  {
    $delimiter = $default_delimiter = ';';
    $queries = [];
    $blocks = preg_split('/^DELIMITER\s+/im', $content);

    foreach ($blocks as $block) {
      if (preg_match('/(.+?)\n/s', $block, $matches)) {
        $new_delimiter = trim($matches[1]);
        $block = preg_replace('/^.+?\n/', '', $block, 1);

        if ($new_delimiter !== $delimiter) {
          $block_queries = explode($new_delimiter, $block);
          foreach ($block_queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
              $queries[] = $query . $default_delimiter;
            }
          }
          $delimiter = $new_delimiter;
        } else {
          $queries[] = $block;
        }
      } else {
        $queries[] = $block;
      }
    }

    foreach ($queries as $query) {
      if (empty(trim($query))) {
        continue;
      }
      Database::sqlRun($query);
    }
  }
}
