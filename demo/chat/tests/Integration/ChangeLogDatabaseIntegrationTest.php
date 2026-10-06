<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Exception\BackupException;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;

/**
 * The chat migration and startup contract against both live test databases.
 */
final class ChangeLogDatabaseIntegrationTest extends IntegrationTestCase
{
    /** @var array<string, list<string>> Exact columns of the six journal tables. */
    private const array COLUMNS = [
        'hilos_change_log_table' => ['id', 'name'],
        'hilos_change_log_field' => ['id', 'table_id', 'name'],
        'hilos_change_log_receipt' => [
            'id', 'created_at', 'actor_user_id', 'subject_user_id', 'session_id',
            'channel', 'action', 'agent', 'source',
        ],
        'hilos_change_log' => [
            'id', 'created_at', 'receipt_id', 'table_id', 'record_key', 'record_key_hash', 'mutation_type',
        ],
        'hilos_change_log_change' => [
            'id', 'created_at', 'log_id', 'field_id', 'kind',
            'old_present', 'new_present', 'old_value', 'new_value',
        ],
        'hilos_change_log_value' => ['id', 'created_at', 'change_id', 'old_value', 'new_value'],
    ];

    /** @var array<string, array<string, string>> Required composite indexes in order. */
    private const array INDEXES = [
        'hilos_change_log_receipt' => [
            'PRIMARY' => 'id,created_at',
            'idx_hcl_receipt_created' => 'created_at,id',
            'idx_hcl_receipt_actor' => 'actor_user_id,created_at,id',
            'idx_hcl_receipt_channel' => 'channel,created_at,id',
        ],
        'hilos_change_log' => [
            'PRIMARY' => 'id,created_at',
            'idx_hcl_receipt' => 'receipt_id,created_at,id',
            'idx_hcl_table_created' => 'table_id,created_at,id',
            'idx_hcl_table_record' => 'table_id,record_key_hash,created_at,id',
        ],
        'hilos_change_log_change' => [
            'PRIMARY' => 'id,created_at',
            'idx_hcl_change_log' => 'log_id,created_at,id',
            'idx_hcl_change_field' => 'field_id,created_at,log_id',
        ],
        'hilos_change_log_value' => [
            'PRIMARY' => 'id,created_at',
            'uk_hcl_value_change' => 'change_id,created_at',
        ],
    ];

    /** Stable backup id for the test's isolated /tmp output. */
    private const string BACKUP_ID = '2026-10-06_00-00-00';

    /**
     * @throws DatabaseException When the live schema cannot be inspected
     */
    public function testSeparateSchemaHasTheSixTablesAndTheirIndexes(): void
    {
        $this->assertSame([0, 1], Database::getConfiguredIndices());
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX)->database;
        $database = Database::getConnectionConfig(ChangeLogDatabase::CONNECTION_INDEX)->database;
        $this->assertSame(ChangeLogDatabase::name($primary), $database);

        Database::sql(
            'SELECT TABLE_NAME AS `name` FROM INFORMATION_SCHEMA.TABLES '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            [$primary, 'BASE TABLE'],
        );
        $mainTables = array_column(Database::rows(), 'name');
        foreach (array_keys(self::COLUMNS) as $table) {
            $this->assertNotContains($table, $mainTables, "April table {$table} remains in the primary database");
        }

        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Database::sql(
                'SELECT TABLE_NAME AS `name` FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
                [$database, 'BASE TABLE'],
            );
            $tables = array_column(Database::rows(), 'name');
            $expectedTables = array_keys(self::COLUMNS);
            sort($expectedTables);
            $this->assertSame($expectedTables, $tables);

            Database::sql(
                'SELECT TABLE_NAME AS `table_name`, COLUMN_NAME AS `column_name` '
                . 'FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? '
                . 'ORDER BY TABLE_NAME, ORDINAL_POSITION',
                [$database],
            );
            $columns = [];
            foreach (Database::rows() as $row) {
                $columns[$row['table_name']][] = $row['column_name'];
            }
            foreach (self::COLUMNS as $table => $expectedColumns) {
                $this->assertSame($expectedColumns, $columns[$table] ?? null, "Columns of {$table}");
            }

            Database::sql(
                'SELECT TABLE_NAME AS `table_name`, INDEX_NAME AS `name`, '
                . 'GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ",") AS `columns` '
                . 'FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? '
                . 'GROUP BY TABLE_NAME, INDEX_NAME',
                [$database],
            );
            $indexes = [];
            foreach (Database::rows() as $row) {
                $indexes[$row['table_name']][$row['name']] = $row['columns'];
            }
            foreach (self::INDEXES as $table => $expected) {
                foreach ($expected as $name => $columnsInIndex) {
                    $this->assertSame($columnsInIndex, $indexes[$table][$name] ?? null, "Index {$table}.{$name}");
                }
            }
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * @throws DatabaseException When partition metadata or preparation fails
     */
    public function testMonthlyPartitionsAndRepeatPreparation(): void
    {
        Database::sql("SELECT DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01') AS `month_start`");
        $month = new DateTimeImmutable((string)Database::field('month_start'), new DateTimeZone('UTC'));
        ChangeLogPartitions::ensure($month);
        $before = self::partitionNames();
        $expected = [];
        for ($offset = 0; $offset <= 24; $offset++) {
            $expected[] = 'p' . $month->modify("+{$offset} months")->format('Ym');
        }
        $expected[] = 'p_future';
        foreach (ChangeLogPartitions::TABLES as $table) {
            $this->assertSame($expected, array_slice($before[$table] ?? [], -count($expected)), "Partitions of {$table}");
        }

        ChangeLogPartitions::ensure($month);
        ChangeLogPartitions::ensure($month);
        $this->assertSame($before, self::partitionNames());
    }

    /**
     * A missing provisioned schema refuses a connection and names the needed database.
     *
     * @throws DatabaseException When configuring or restoring the connection fails
     */
    public function testMissingProvisionRefusesAndNamesJournalDatabase(): void
    {
        $config = Database::getConnectionConfig(ChangeLogDatabase::CONNECTION_INDEX);
        $missing = ChangeLogDatabase::name('hilos-chat-missing-provision');
        Database::close(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Database::configure(
                ChangeLogDatabase::CONNECTION_INDEX,
                $config->host,
                $config->user,
                $config->password,
                $missing,
                $config->port,
                $config->charset,
            );
            try {
                Database::connect(ChangeLogDatabase::CONNECTION_INDEX);
                $this->fail('A database without provision must not connect');
            } catch (DatabaseConnectionException $refusal) {
                $this->assertStringContainsString($missing, $refusal->getMessage());
            }
        } finally {
            Database::configure(
                ChangeLogDatabase::CONNECTION_INDEX,
                $config->host,
                $config->user,
                $config->password,
                $config->database,
                $config->port,
                $config->charset,
            );
            Database::connect(ChangeLogDatabase::CONNECTION_INDEX);
        }
    }

    /**
     * The existing creator must still capture chat after connection 1 is configured.
     *
     * @throws BackupException When either database cannot be dumped or archived
     */
    public function testBackupCreatorCapturesBothDatabases(): void
    {
        $root = sys_get_temp_dir() . '/hilos-chat-change-log-backup-' . getmypid();
        $previous = getenv('BACKUP_DIR');
        putenv('BACKUP_DIR=' . $root);
        try {
            $metadata = new BackupCreator()->create(self::BACKUP_ID, BackupScope::SCHEMA_ONLY);
            $this->assertSame([0, 1], array_map(static fn ($connection) => $connection->index, $metadata->connections));
            $this->assertSame(
                ChangeLogDatabase::configuredName(),
                $metadata->connections[1]->database,
            );
            Database::sql(
                'SELECT COUNT(*) AS `tables` FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = ?',
                [ChangeLogDatabase::configuredName()],
            );
            $this->assertSame(count(self::COLUMNS), (int)Database::field('tables'));
            $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $previous === false ? putenv('BACKUP_DIR') : putenv('BACKUP_DIR=' . $previous);
            foreach (glob($root . '/*/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($root . '/*') ?: [] as $directory) {
                rmdir($directory);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    /**
     * @return array<string, list<string>> Partition names in ordinal order by table
     * @throws DatabaseException When partition metadata cannot be read
     */
    private static function partitionNames(): array
    {
        $database = ChangeLogDatabase::configuredName();
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
    }
}
