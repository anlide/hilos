<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\FullRestore\Dump;
use Hilos\Backup\FullRestore\MigrationHistory;
use Hilos\Backup\FullRestore\MigrationHistoryState;
use Hilos\Backup\FullRestore\MigrationRow;
use Hilos\Backup\FullRestore\PreparedTarget;
use Hilos\Backup\FullRestoreMigrationEvidence;
use Hilos\Backup\FullRestorePlanner;
use Hilos\Backup\FullRestoreSchemaSnapshot;
use Hilos\Constants\EnvConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\MigrationSqlReader;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Throwable;

/** Real journal schema replacement in disposable paired databases, outside rollback scopes. */
final class JournalMomentStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261110164648748072_HIL-1929_journal_moments';
    private const array SOURCES = ['hilos_user', 'hilos_identity', 'hilos_second_factor', 'hilos_setting', 'hilos_moderation_rule'];
    private const array MOMENTS = [
        '1969-12-31 23:59:59.998765' => -1,
        '2026-01-15 12:34:56.123456' => 1768480496123,
        '2040-01-01 00:00:00.456789' => 2208988800456,
    ];

    private string $database;
    private string $journal;
    private string $root;
    private string $deliveredUp;
    /** @var list<string> Schemas created by this case, including a partial pair */
    private array $created = [];
    private array $previousMigration = [];

    /** Creates a disposable pair and copies the real delivered migration pair into its track. */
    protected function setUp(): void
    {
        parent::setUp();
        try {
            foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
                $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
            }
            new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
            $this->database = 'hilos-journal-moments-' . bin2hex(random_bytes(6));
            $this->journal = ChangeLogDatabase::name($this->database);
            $this->root = sys_get_temp_dir() . '/' . $this->database;
            mkdir($this->root . '/Schema', 0700, true);
            $config = Database::getConnectionConfig(0);
            $remoteHost = getenv('HILOS_TEST_REMOTE_DB_HOST');
            $remotePort = getenv('HILOS_TEST_REMOTE_DB_PORT');
            $this->assertIsString($remoteHost);
            $this->assertIsString($remotePort);
            $this->assertNotSame('', $remoteHost);
            $this->assertNotSame('', $remotePort);
            Database::close(0);
            Database::configure(0, $remoteHost, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
                'information_schema', (int)$remotePort, $config->charset);
            Database::connect();
            Database::sql('CREATE DATABASE `' . $this->database . '`');
            $this->created[] = $this->database;
            Database::sql('CREATE DATABASE `' . $this->journal . '`');
            array_unshift($this->created, $this->journal);
            Database::close();
            Database::configure(0, $remoteHost, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
                $this->database, (int)$remotePort, $config->charset);
            Database::configure(ChangeLogDatabase::CONNECTION_INDEX, $remoteHost, 'root',
                Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(), $this->journal, (int)$remotePort, $config->charset);
            Database::connect();
            $copied = 0;
            foreach (glob(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/*.sql') as $file) {
                if (!str_contains(file_get_contents($file), self::CHANGE)) {
                    continue;
                }
                $destination = $this->root . '/Schema/' . basename($file);
                copy($file, $destination);
                if (!str_ends_with($file, '_down.sql')) {
                    $this->deliveredUp = file_get_contents($file);
                }
                $copied++;
            }
            $this->assertSame(2, $copied);
            new FrameworkMigrationCatalog(new FrameworkSchemaCatalog())->verifyPair(
                $this->deliveredUp,
                file_get_contents(glob($this->root . '/Schema/*_down.sql')[0]),
            );
            Migration::setMigrationListPath($this->root);
            Migration::setMigrationName('Schema');
            Migration::initialize();
        } catch (Throwable $failure) {
            try {
                $this->tearDown();
            } finally {
                throw $failure;
            }
        }
    }

    /** Restores migration statics and releases both schemas even after an assertion fails. */
    protected function tearDown(): void
    {
        try {
            Database::useConnection(0);
            foreach ($this->created as $database) {
                Database::sql('DROP DATABASE IF EXISTS `' . $database . '`');
            }
            $this->created = [];
            if (isset($this->root) && is_dir($this->root . '/Schema')) {
                foreach (glob($this->root . '/Schema/*') as $file) {
                    unlink($file);
                }
                rmdir($this->root . '/Schema');
                rmdir($this->root);
            }
        } finally {
            foreach ($this->previousMigration as $property => $value) {
                new ReflectionProperty(Migration::class, $property)->setValue(null, $value);
            }
            parent::tearDown();
        }
    }

    public function testFreshStubUsesSignedBigintAndNumericMonthBoundaries(): void
    {
        $this->schema();
        $this->assertPhysicalMoments();
        ChangeLogPartitions::ensure(new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')));
        $partitions = Database::sql('SELECT TABLE_NAME, PARTITION_DESCRIPTION FROM INFORMATION_SCHEMA.PARTITIONS'
            . " WHERE TABLE_SCHEMA = ? AND PARTITION_NAME = 'p202601'", [$this->journal])->rows();
        $this->assertCount(4, $partitions);
        foreach ($partitions as $partition) {
            $this->assertSame('1769904000000', $partition['PARTITION_DESCRIPTION']);
        }
    }

    public function testDeliveredUpConvertsEveryRowPreservingIdsIndexesAndTruncatedMilliseconds(): void
    {
        $this->schema(legacy: true);
        $this->seedLegacy();
        $before = $this->rows();
        $indexes = $this->indexes();
        $this->assertSame(1, Migration::migrateUp());
        $this->assertPhysicalMoments();
        $this->assertSame($indexes, $this->indexes());
        foreach ($this->rows() as $table => $rows) {
            foreach ($rows as $position => $row) {
                $expected = $before[$table][$position];
                $expected['created_at'] = self::MOMENTS[$expected['created_at']];
                $row['created_at'] = (int)$row['created_at'];
                $this->assertSame($expected, $row, $table);
            }
        }
        $this->assertSame(0, Migration::migrateUp());
    }

    public function testUpRemovesEveryFrameworkTriggerBeforeReplacingTheJournal(): void
    {
        $this->schema(legacy: true);
        $this->seedLegacy();
        foreach (self::SOURCES as $table) {
            Database::sql('CREATE TABLE `' . $table . '` (`id` INT NOT NULL)');
            foreach (['insert', 'update', 'delete'] as $event) {
                Database::sql('CREATE TRIGGER `hilos_cl_' . $table . '_after_' . $event . '` AFTER '
                    . strtoupper($event) . ' ON `' . $table . '` FOR EACH ROW BEGIN'
                    . ' DECLARE v_created_at DATETIME(6); SET v_created_at = UTC_TIMESTAMP(6);'
                    . ' SET @legacy_journal_writer = v_created_at; END');
            }
        }
        $this->assertSame(15, count(Database::sql('SHOW TRIGGERS')->rows()));
        $this->assertSame(1, Migration::migrateUp());
        $this->assertSame([], Database::sql('SHOW TRIGGERS')->rows());
        Database::sql('SET @legacy_journal_writer = NULL');
        Database::sql('INSERT INTO hilos_user VALUES (1)');
        $this->assertNull(Database::sql('SELECT @legacy_journal_writer AS written')->field('written'));
    }

    public function testDownAndUpPreserveExactMillisecondsAcrossTheAtomicReplacement(): void
    {
        $this->schema(legacy: true);
        $this->seedLegacy();
        $this->assertSame(1, Migration::migrateUp());
        $before = $this->rows();
        $this->assertSame(1, Migration::migrateDown(0));
        foreach (ChangeLogPartitions::TABLES as $table) {
            $this->assertSame('datetime(6)', Database::sql('SHOW COLUMNS FROM `' . $this->journal . '`.`' . $table . '`'
                . " LIKE 'created_at'")->field('Type'));
        }
        $this->assertSame(1, Migration::migrateUp());
        $this->assertSame($before, $this->rows());
        $this->assertPhysicalMoments();
    }

    /**
     * @param string $table Journal table carrying an unrepresentable moment
     * @param int $moment Outside the legacy DATETIME range
     */
    #[DataProvider('unrepresentableMoments')]
    public function testDownRefusesBeforeReplacingAnyTable(string $table, int $moment): void
    {
        $this->schema(legacy: true);
        $this->seedLegacy();
        $this->assertSame(1, Migration::migrateUp());
        Database::sql('UPDATE `' . $this->journal . '`.`' . $table . '` SET created_at = ? WHERE id = 1', [$moment]);
        $before = $this->rows();
        try {
            Migration::migrateDown(0);
            $this->fail('Unrepresentable moments must refuse DOWN before replacing a table');
        } catch (DatabaseException $failure) {
            $this->assertStringContainsString('Journal moment cannot be represented by the legacy type', $failure->getMessage());
        }
        $this->assertSame($before, $this->rows());
        $this->assertPhysicalMoments();
        $this->assertSame([], Database::sql('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES'
            . " WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE '__hil1929%'", [$this->journal])->rows());
    }

    /** @return iterable<string, array{string, int}> Both legacy bounds on every table */
    public static function unrepresentableMoments(): iterable
    {
        foreach (ChangeLogPartitions::TABLES as $table) {
            yield $table . ' below' => [$table, -30610224000001];
            yield $table . ' above' => [$table, 253402300800000];
        }
    }

    public function testDeliveredUpIsFullyReadableByTheFullRestoreOperationPlanner(): void
    {
        $this->schema(legacy: true);
        $counts = [];
        $sql = str_replace('{{change_log_database}}', '`' . $this->journal . '`', $this->deliveredUp);
        foreach (MigrationSqlReader::statements($sql) as $statement) {
            foreach (MigrationOperationReader::read($statement, $this->database) as $operation) {
                $this->assertNotSame(MigrationOperationKind::UNKNOWN, $operation->kind, $statement);
                $counts[$operation->kind->name] = ($counts[$operation->kind->name] ?? 0) + 1;
            }
        }
        $this->assertSame(['DROP_TRIGGER' => 15, 'DROP_TABLE' => 8, 'CREATE_TABLE' => 4, 'DATA' => 4, 'RENAME_TABLE' => 8], $counts);
        $targets = [];
        foreach ([0, ChangeLogDatabase::CONNECTION_INDEX] as $index) {
            Database::useConnection($index);
            $objects = [];
            foreach (Database::sql('SHOW TABLES')->rows() as $row) {
                $name = array_values($row)[0];
                $create = array_values(Database::sql('SHOW CREATE TABLE `' . $name . '`')->row())[1];
                $objects[$name] = MigrationOperationReader::read($create, Database::getConnectionConfig($index)->database)[0]->definition;
            }
            $history = $index === 0
                ? new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(0, false)])
                : new MigrationHistory(MigrationHistoryState::ABSENT, []);
            $address = array_values(Database::sql('SELECT @@hostname, @@port, @@server_id, DATABASE()')->row());
            $targets[] = new PreparedTarget(new BackupConnectionMeta($index, 'journal-moments', $index === 0 ? 0 : null),
                Database::getConnectionConfig($index), json_encode($address), 'source', 'digest', 'staged', 'digest',
                new Dump($objects, $history));
        }
        Database::useConnection(0);
        $evidence = FullRestoreMigrationEvidence::prepare($targets);
        $schema = FullRestoreSchemaSnapshot::read($targets);
        $this->assertSame([], FullRestorePlanner::prove($evidence->targets, $evidence->track, $schema));
        $evidence->assertUnchanged();
        $schema->assertUnchanged($targets);
    }

    /** @param bool $legacy Whether to create the pre-migration DATETIME schema with a real monthly partition */
    private function schema(bool $legacy = false): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_change_log.sql');
        if ($legacy) {
            $sql = str_replace('`created_at` BIGINT NOT NULL', '`created_at` DATETIME(6) NOT NULL', $sql);
            $sql = str_replace('    PARTITION `p_future`',
                "    PARTITION `p202601` VALUES LESS THAN ('2026-02-01'),\n    PARTITION `p_future`", $sql);
        }
        $sql = str_replace('{{change_log_database}}', '`' . $this->journal . '`', $sql);
        foreach (MigrationSqlReader::statements($sql) as $statement) {
            Database::sql($statement);
        }
    }

    private function seedLegacy(): void
    {
        $journal = '`' . $this->journal . '`';
        Database::sql("INSERT INTO {$journal}.hilos_change_log_table VALUES (1, 'sample')");
        Database::sql("INSERT INTO {$journal}.hilos_change_log_field VALUES (1, 1, 'body')");
        $id = 0;
        foreach (array_keys(self::MOMENTS) as $moment) {
            $id++;
            Database::sql("INSERT INTO {$journal}.hilos_change_log_receipt (id, created_at, channel, action) VALUES (?, ?, 'cli', 'sample')",
                [$id, $moment]);
            Database::sql("INSERT INTO {$journal}.hilos_change_log (id, created_at, receipt_id, table_id, record_key, mutation_type)"
                . " VALUES (?, ?, ?, 1, '[1]', 'update')", [$id, $moment, $id]);
            Database::sql("INSERT INTO {$journal}.hilos_change_log_change"
                . ' (id, created_at, log_id, field_id, kind, old_present, new_present, old_value, new_value)'
                . " VALUES (?, ?, ?, 1, 'long', 1, 1, NULL, NULL)", [$id, $moment, $id]);
            Database::sql("INSERT INTO {$journal}.hilos_change_log_value (id, created_at, change_id, old_value, new_value)"
                . " VALUES (?, ?, ?, 'old body', 'new body')", [$id, $moment, $id]);
        }
    }

    /** @return array<string, list<array<string, mixed>>> Persisted rows on the primary connection */
    private function rows(): array
    {
        $rows = [];
        foreach (ChangeLogPartitions::TABLES as $table) {
            $rows[$table] = Database::sql('SELECT * FROM `' . $this->journal . '`.`' . $table . '` ORDER BY id')->rows();
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> Index definitions independent of storage and partition count */
    private function indexes(): array
    {
        return Database::sql('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, INDEX_TYPE'
            . ' FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$this->journal])->rows();
    }

    private function assertPhysicalMoments(): void
    {
        foreach (ChangeLogPartitions::TABLES as $table) {
            $row = Database::sql('SHOW COLUMNS FROM `' . $this->journal . '`.`' . $table . "` LIKE 'created_at'")->row();
            $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', $row['Type']);
            $this->assertSame('NO', $row['Null']);
            $this->assertNull($row['Default']);
            $this->assertSame('', $row['Extra']);
            $partitions = Database::sql('SELECT PARTITION_METHOD, PARTITION_EXPRESSION, PARTITION_DESCRIPTION'
                . ' FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$this->journal, $table])->rows();
            $this->assertCount(1, $partitions);
            $this->assertSame('RANGE COLUMNS', $partitions[0]['PARTITION_METHOD']);
            $this->assertSame('created_at', trim($partitions[0]['PARTITION_EXPRESSION'], '`'));
            $this->assertSame('MAXVALUE', $partitions[0]['PARTITION_DESCRIPTION']);
        }
    }
}
