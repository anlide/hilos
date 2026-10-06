<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use mysqli;

/**
 * Partition rollout and database isolation against the framework's paired test databases.
 */
final class ChangeLogPartitionsIntegrationTest extends FrameworkIntegrationTestCase
{
    /** The row staged beyond the initial monthly window. */
    private const string FUTURE_ROW_AT = '2030-01-15 12:00:00';

    /**
     * @throws DatabaseException When the paired schema cannot be prepared
     */
    protected function setUp(): void
    {
        parent::setUp();
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(
            ChangeLogDatabase::CONNECTION_INDEX,
            $primary->host,
            $primary->user,
            $primary->password,
            ChangeLogDatabase::name($primary->database),
            $primary->port,
            $primary->charset,
        );
        Database::connect(ChangeLogDatabase::CONNECTION_INDEX);
        Migration::initialize();
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            foreach (ChangeLogPartitions::TABLES as $table) {
                Database::sql('DROP TABLE IF EXISTS `' . $table . '`');
                Database::sql(
                    'CREATE TABLE `' . $table . '` ('
                    . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
                    . '`created_at` DATETIME(6) NOT NULL, PRIMARY KEY (`id`, `created_at`)) '
                    . 'PARTITION BY RANGE COLUMNS (`created_at`) ('
                    . 'PARTITION `p_future` VALUES LESS THAN (MAXVALUE))',
                );
            }
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * @throws DatabaseException When fixture cleanup fails
     */
    protected function tearDown(): void
    {
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            foreach (ChangeLogPartitions::TABLES as $table) {
                Database::sql('DROP TABLE IF EXISTS `' . $table . '`');
            }
            Database::close(ChangeLogDatabase::CONNECTION_INDEX);
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            parent::tearDown();
        }
    }

    /**
     * @throws DatabaseException When partition DDL or introspection fails
     */
    public function testBoundaryMonthAndRepeatAreIdempotent(): void
    {
        ChangeLogPartitions::ensure(self::month('2026-01-31'));
        $january = self::partitions();
        foreach (ChangeLogPartitions::TABLES as $table) {
            $this->assertCount(26, $january[$table]);
            $this->assertSame('p202601', $january[$table][0]);
            $this->assertSame('p202801', $january[$table][24]);
            $this->assertSame('p_future', $january[$table][25]);
        }

        ChangeLogPartitions::ensure(self::month('2026-02-01'));
        $february = self::partitions();
        foreach (ChangeLogPartitions::TABLES as $table) {
            $this->assertSame('p202802', $february[$table][25]);
            $this->assertSame('p_future', $february[$table][26]);
        }
        ChangeLogPartitions::ensure(self::month('2026-02-01'));
        $this->assertSame($february, self::partitions());
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
    }

    /**
     * A row written after a very long uptime lands in p_future, then moves to its month.
     *
     * @throws DatabaseException When DDL, insert, or partition read fails
     */
    public function testFuturePartitionKeepsRowsAcrossLongUptime(): void
    {
        ChangeLogPartitions::ensure(self::month('2026-01-01'));
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Database::sql(
                'INSERT INTO `hilos_change_log_receipt` (`created_at`) VALUES (?)',
                [self::FUTURE_ROW_AT],
            );
            $rowId = Database::lastInsertId();
            Database::sql('SELECT `id` FROM `hilos_change_log_receipt` PARTITION (`p_future`) WHERE `id` = ?', [$rowId]);
            $this->assertSame($rowId, (int)Database::field('id'));
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }

        ChangeLogPartitions::ensure(self::month('2030-01-01'));
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Database::sql('SELECT `id` FROM `hilos_change_log_receipt` PARTITION (`p203001`) WHERE `id` = ?', [$rowId]);
            $this->assertSame($rowId, (int)Database::field('id'));
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * A named monthly partition with the wrong bound must not silently accept new DDL.
     *
     * @throws DatabaseException When the existing partition is rejected
     */
    public function testWrongBoundaryRefusesBeforeExtendingTheWindow(): void
    {
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Database::sql(
                "ALTER TABLE `hilos_change_log_receipt` REORGANIZE PARTITION `p_future` INTO ("
                . "PARTITION `p202601` VALUES LESS THAN ('2026-03-01'), "
                . 'PARTITION `p_future` VALUES LESS THAN (MAXVALUE))',
            );
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('partition p202601 has a wrong boundary');
        ChangeLogPartitions::ensure(self::month('2026-01-01'));
    }

    /**
     * A paired schema follows its own primary name, not another integration piece's.
     *
     * @throws DatabaseException When configuring or reading either schema fails
     */
    public function testTwoFrameworkPiecesDoNotShareJournalTables(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        if (preg_match('/-[0-9]+$/', $primary->database) !== 1) {
            // The standalone integration script uses the unnumbered default database;
            // only the piece runner provisions its four neighboring pairs.
            $this->assertSame(ChangeLogDatabase::name($primary->database), ChangeLogDatabase::configuredName());
            return;
        }
        $otherPrimary = str_ends_with($primary->database, '-1')
            ? substr($primary->database, 0, -1) . '2'
            : substr($primary->database, 0, -1) . '1';
        $otherJournal = ChangeLogDatabase::name($otherPrimary);
        $this->assertNotSame(ChangeLogDatabase::configuredName(), $otherJournal);
        $other = new mysqli($primary->host, $primary->user, $primary->password, $otherJournal, $primary->port);
        try {
            $result = $other->query("SHOW TABLES LIKE 'hilos_change_log_receipt'");
            $this->assertSame(0, $result->num_rows, 'The other piece must not see this fixture table');
        } finally {
            $other->close();
        }
    }

    /**
     * @param string $date UTC date in the month under test
     * @return DateTimeImmutable Test instant
     */
    private static function month(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, list<string>> Partition names in ordinal order
     * @throws DatabaseException When metadata cannot be read
     */
    private static function partitions(): array
    {
        $database = ChangeLogDatabase::configuredName();
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Database::sql(
                'SELECT TABLE_NAME AS `table_name`, PARTITION_NAME AS `name` '
                . 'FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA = ? '
                . 'ORDER BY TABLE_NAME, PARTITION_ORDINAL_POSITION',
                [$database],
            );
            $partitions = [];
            foreach (Database::rows() as $row) {
                $partitions[$row['table_name']][] = $row['name'];
            }

            return $partitions;
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }
}
