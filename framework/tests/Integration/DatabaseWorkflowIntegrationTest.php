<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Integration workflow for Hilos\Database\Database: ping, SQL, DDL, foreign keys, DML.
 *
 * Test order is enforced with PHPUnit #[Depends]. Run MariaDB first (composer run test:framework:up),
 * then composer run test:framework:integration. Table names use the hilos_fw_test_* prefix.
 */
final class DatabaseWorkflowIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Scratch table for CREATE / ALTER / DROP. */
    private const string DDL_TABLE = 'hilos_fw_test_ddl';

    /** Parent table for FK and DML tests. */
    private const string PARENT_TABLE = 'hilos_fw_test_parent';

    /**
     * Child table for FK and DML tests.
     *
     * @see self::PARENT_TABLE
     */
    private const string CHILD_TABLE = 'hilos_fw_test_child';

    /**
     * Asserts Database::ping() succeeds (internal SELECT 1).
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    public function testPingReturnsTrueWhenConnected(): void
    {
        $this->assertTrue(Database::ping());
    }

    /**
     * Bound SELECT, server string, DATABASE() vs DB_DATABASE, and information_schema.SCHEMATA.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    #[Depends('testPingReturnsTrueWhenConnected')]
    public function testSelectOneEnvAndInformationSchema(): void
    {
        Database::sql('SELECT ? AS v', [1]);
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['v']);

        $this->assertNotSame('', trim(Database::getServerInfo()));

        $expectedDb = Hilos::$env[EnvConstants::DB_DATABASE];
        Database::sql('SELECT DATABASE() AS db');
        $dbRow = Database::row();
        $this->assertNotNull($dbRow);
        $this->assertSame($expectedDb, $dbRow['db'], 'DATABASE() must match DB_DATABASE from environment');

        Database::sql(
            'SELECT COUNT(*) AS c FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$expectedDb],
        );
        $schemaRow = Database::row();
        $this->assertNotNull($schemaRow);
        $this->assertSame(1, (int) $schemaRow['c']);
    }

    /**
     * DDL lifecycle on {@see self::DDL_TABLE}: drop if exists, create, alter, show columns, drop, verify gone.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    #[Depends('testSelectOneEnvAndInformationSchema')]
    public function testDdlCreateAlterDrop(): void
    {
        $ddl = self::DDL_TABLE;
        Database::sql("DROP TABLE IF EXISTS `{$ddl}`");

        Database::sql('CREATE TABLE `' . $ddl . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `label` VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=' . DatabaseConnectionDefaults::CHARSET);

        Database::sql("ALTER TABLE `{$ddl}` ADD COLUMN `extra` VARCHAR(10) NOT NULL DEFAULT ''");

        Database::sql("SHOW COLUMNS FROM `{$ddl}` LIKE 'extra'");
        $col = Database::row();
        $this->assertNotNull($col);
        $this->assertSame('extra', $col['Field']);

        Database::sql("DROP TABLE `{$ddl}`");

        Database::sql(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$ddl],
        );
        $gone = Database::row();
        $this->assertNotNull($gone);
        $this->assertSame(0, (int) $gone['c']);
    }

    /**
     * Creates parent and child InnoDB tables with ON DELETE CASCADE FK; asserts KEY_COLUMN_USAGE row.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    #[Depends('testDdlCreateAlterDrop')]
    public function testCreateTwoTablesWithForeignKey(): void
    {
        $parent = self::PARENT_TABLE;
        $child = self::CHILD_TABLE;

        Database::sql("DROP TABLE IF EXISTS `{$child}`");
        Database::sql("DROP TABLE IF EXISTS `{$parent}`");

        Database::sql('CREATE TABLE `' . $parent . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=' . DatabaseConnectionDefaults::CHARSET);

        Database::sql('CREATE TABLE `' . $child . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `parent_id` INT UNSIGNED NOT NULL,
            `note` VARCHAR(64) NOT NULL,
            CONSTRAINT `fk_hilos_fw_child_parent` FOREIGN KEY (`parent_id`) REFERENCES `' . $parent . '`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=' . DatabaseConnectionDefaults::CHARSET);

        Database::sql(
            'SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND REFERENCED_TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [$child, $parent, 'parent_id'],
        );
        $fk = Database::row();
        $this->assertNotNull($fk);
        $this->assertSame(1, (int) $fk['c']);
    }

    /**
     * Inserts and mutates rows on {@see self::PARENT_TABLE} and {@see self::CHILD_TABLE}, then drops both.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    #[Depends('testCreateTwoTablesWithForeignKey')]
    public function testInsertUpdateDelete(): void
    {
        $parent = self::PARENT_TABLE;
        $child = self::CHILD_TABLE;

        Database::sql("INSERT INTO `{$parent}` (`name`) VALUES (?)", ['parent-a']);
        $parentId = Database::lastInsertId();
        $this->assertGreaterThan(0, $parentId);

        Database::sql("INSERT INTO `{$child}` (`parent_id`, `note`) VALUES (?, ?)", [$parentId, 'note-v1']);
        $childId = Database::lastInsertId();
        $this->assertGreaterThan(0, $childId);

        Database::sql("UPDATE `{$child}` SET `note` = ? WHERE `id` = ?", ['note-v2', $childId]);
        $this->assertSame(1, Database::affectedRows());

        Database::sql("SELECT `note` FROM `{$child}` WHERE `id` = ?", [$childId]);
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertSame('note-v2', $row['note']);

        Database::sql("DELETE FROM `{$child}` WHERE `id` = ?", [$childId]);
        $this->assertSame(1, Database::affectedRows());

        Database::sql("DELETE FROM `{$parent}` WHERE `id` = ?", [$parentId]);
        $this->assertSame(1, Database::affectedRows());

        Database::sql("DROP TABLE IF EXISTS `{$child}`");
        Database::sql("DROP TABLE IF EXISTS `{$parent}`");
    }
}
