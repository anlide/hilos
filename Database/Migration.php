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
  /**
   * @throws Sql
   */
  public static function up(): void
  {
    print('Start migration'."\n");
    $migrationList = self::getMigrationList();
    self::filterPassedList($migrationList);
    self::passMigration($migrationList);
  }

  /**
   * @throws Sql
   */
  public static function down(): void
  {
    print('Start migration down'."\n");
    $migrationList = self::getMigrationList();
    $indexMax = self::getMaxPassedIndex();
    self::rollback($indexMax, $migrationList);
  }

  /**
   * @return array
   */
  private static function getMigrationList(): array {
    $list = [];
    $path = Config::root() . 'data/migrations';
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
   * @return array
   * @throws Sql
   */
  private static function getMaxPassedIndex(): array {
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
    $path = Config::root() . 'data/migrations';
    foreach ($list as $index) {
      $fileName = $index.'migration-up.sql';
      $content = file_get_contents($path.'/'.$fileName);
      print('Run migration #'.$index.' ...');
      Database::sqlRun("INSERT INTO `migration` (`index`, `failed`) VALUES (?, true);", [intval($index)]);
      Database::sqlRun($content);
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
    $path = Config::root() . 'data/migrations';
    $fileName = $index.'migration-down.sql';
    $content = file_get_contents($path.'/'.$fileName);
    print('Rollback migration #'.$index.' ...');
    Database::sqlRun('UPDATE `migration` SET `failed` = true WHERE `index` = ?;', [intval($index)]);
    Database::sqlRun($content);
    Database::sqlRun('DELETE FROM `migration` WHERE `index` = ?;', [intval($index)]);
    print(' done'."\n");
  }
}