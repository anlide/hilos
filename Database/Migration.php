<?php

namespace Hilos\Database;
use Hilos\Service\Config;

/**
 * Migration system.
 *
 * Class Database
 * @package Hilos\Database
 */
class Migration {
  public static function up() {
    print('Start migration'."\n");
    $migrationList = self::getMigrationList();
    self::filterPassedList($migrationList);
    self::passMigration($migrationList);
  }
  public static function down() {
    print('Start migration down'."\n");
    $migrationList = self::getMigrationList();
    $indexMax = self::getMaxPassedIndex();
    self::rollback($indexMax, $migrationList);
  }
  private static function getMigrationList() {
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
  private static function createMigrationTable() {
    $row = Database::row("SHOW TABLES LIKE 'migration';");
    if ($row === null) {
      Database::sql('CREATE TABLE `migration` (
  `index` int(10) UNSIGNED NOT NULL,
  `failed` tinyint(1) NOT NULL DEFAULT \'1\'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
      Database::sql('ALTER TABLE `migration` ADD PRIMARY KEY(`index`);');
    }
  }
  private static function filterPassedList(&$list) {
    self::createMigrationTable();
    $passedMigrations = Database::rows('SELECT * FROM `migration`;');
    foreach ($passedMigrations as $passedMigration) {
      if ($passedMigration['failed']) {
        throw new \Exception('We have migration #' . $passedMigration['index'] . ' in failed state.');
      }
      $index = str_pad($passedMigration['index'], 3, '0', STR_PAD_LEFT);
      unset($list[$index]);
    }
  }
  private static function getMaxPassedIndex() {
    self::createMigrationTable();
    $countFailed = Database::field('SELECT COUNT(*) AS `count` FROM `migration` WHERE `failed` = true;');
    if ($countFailed > 0) {
      throw new \Exception('We have '.$countFailed.' migrations in failed state.');
    }
    return Database::field('SELECT MAX(`index`) AS `max` FROM `migration`;');
  }
  private static function passMigration($list) {
    $path = Config::root() . 'data/migrations';
    foreach ($list as $index) {
      $fileName = $index.'migration-up.sql';
      $content = file_get_contents($path.'/'.$fileName);
      print('Run migration #'.$index.' ...');
      Database::sql("INSERT INTO `migration` (`index`, `failed`) VALUES (?, true);", [$index]);
      Database::sql($content);
      Database::sql('UPDATE `migration` SET `failed` = false WHERE `index` = ?', [$index]);
      print(' done'."\n");
    }
  }
  private static function rollback($index, $migrationList) {
    $index = str_pad($index, 3, '0', STR_PAD_LEFT);
    if (!isset($migrationList[$index])) {
      throw new \Exception('Wrong rollback operation for migration #'.$index);
    }
    $path = Config::root() . 'data/migrations';
    $fileName = $index.'migration-down.sql';
    $content = file_get_contents($path.'/'.$fileName);
    print('Rollback migration #'.$index.' ...');
    Database::sql('UPDATE `migration` SET `failed` = true WHERE `index` = ?', [$index]);
    Database::sql($content);
    Database::sql('DELETE FROM `migration` WHERE `index` = ?', [$index]);
    print(' done'."\n");
  }
}