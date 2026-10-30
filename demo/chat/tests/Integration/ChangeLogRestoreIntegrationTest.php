<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\AnonymizationStartupGuard;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupRestorer;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreEnvGuard;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\AppEnv;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFile;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerGenerator;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Database\Schema\Schema;
use Hilos\HilosException;
use JsonException;

/** Real dump/import of chat's two databases, with the original pair restored after every case. */
final class ChangeLogRestoreIntegrationTest extends IntegrationTestCase
{
    private const string BASELINE_ID = '2026-10-10_00-00-00';

    private const string ARCHIVE_ID = '2026-10-10_00-00-01';

    /** @var list<string> Exact journal schema, including tables that may carry no rows */
    private const array JOURNAL_TABLES = [
        'hilos_change_log',
        'hilos_change_log_change',
        'hilos_change_log_field',
        'hilos_change_log_receipt',
        'hilos_change_log_table',
        'hilos_change_log_value',
    ];

    /** Frozen verdicts from Code Base 2c7177fff: ChangeLogTablesWithoutEntity::pii(). */
    private const array LEGACY_PII = [
        'hilos_change_log_table' => [],
        'hilos_change_log_field' => [],
        'hilos_change_log_receipt' => [
            'actor_user_id' => AnonymizationStrategy::NULLIFY,
            'subject_user_id' => AnonymizationStrategy::NULLIFY,
            'session_id' => AnonymizationStrategy::NULLIFY,
            'agent' => AnonymizationStrategy::MASK,
            'source' => AnonymizationStrategy::MASK,
        ],
        'hilos_change_log' => [
            'record_key' => AnonymizationStrategy::MASK,
            'record_key_hash' => AnonymizationStrategy::NULLIFY,
        ],
        'hilos_change_log_change' => ['old_value' => AnonymizationStrategy::MASK, 'new_value' => AnonymizationStrategy::MASK],
        'hilos_change_log_value' => ['old_value' => AnonymizationStrategy::MASK, 'new_value' => AnonymizationStrategy::MASK],
    ];

    /** Frozen non-personal columns from the same pre-transfer provider. */
    private const array LEGACY_NOT_PERSONAL = [
        'hilos_change_log_table' => ['id', 'name'],
        'hilos_change_log_field' => ['id', 'table_id', 'name'],
        'hilos_change_log_receipt' => ['id', 'created_at', 'channel', 'action', 'open_handovers'],
        'hilos_change_log' => ['id', 'created_at', 'receipt_id', 'table_id', 'mutation_type'],
        'hilos_change_log_change' => ['id', 'created_at', 'log_id', 'field_id', 'kind', 'old_present', 'new_present'],
        'hilos_change_log_value' => ['id', 'created_at', 'change_id'],
    ];

    private string $storeRoot = '';

    private string|false $previousBackupDir = false;

    private ?BackupMetadata $baseline = null;

    /** @var array<string, array<string, mixed>> Primary trigger definitions before the case */
    private array $originalTriggers = [];

    /** @var array<int, array<string, list<array<string, mixed>>>> Original rows in both databases */
    private array $originalContents = [];

    /**
     * The journal rolls back with the data, without recording the anonymizer's writes.
     *
     * @throws HilosException When a dump, restore or database query fails
     */
    public function testFullRestoreAnonymizesBeforeInstallingTriggers(): void
    {
        $this->assertInstalledTriggersMatchFiles();
        $userId = JournalReceiptScope::run(JournalReceiptData::cli('test:change-log:restore', null), static function (): int {
            Database::sql('INSERT INTO `hilos_user` (`name`) VALUES (?)', ['Archive person']);
            $id = Database::lastInsertId();
            Database::sql('UPDATE `hilos_user` SET `name` = ? WHERE `id` = ?', ['Archived name', $id]);
            return $id;
        });
        $this->seedEveryJournalField();
        $registry = PiiRegistry::collect();
        foreach (self::LEGACY_PII as $table => $strategies) {
            $this->assertSame($strategies, $registry->strategiesFor(ChangeLogDatabase::CONNECTION_INDEX, $table), $table);
            $this->assertSame(self::LEGACY_NOT_PERSONAL[$table], $registry->notPersonalColumns(ChangeLogDatabase::CONNECTION_INDEX, $table));
            $this->assertNull($registry->strategiesFor(DatabaseConnectionDefaults::PRIMARY_INDEX, $table), $table);
        }
        $archivedRows = $this->journalRows();
        new BackupCreator()->create(self::ARCHIVE_ID, BackupScope::FULL);
        $archivedCounts = $this->journalCounts();
        $this->assertGreaterThan(0, $archivedCounts['hilos_change_log']);
        $this->assertGreaterThan(0, $archivedCounts['hilos_change_log_change']);
        $this->assertGreaterThan(0, $archivedCounts['hilos_change_log_receipt']);
        $this->assertArchiveHasNoTriggers(BackupScope::FULL);

        Database::sql('UPDATE `hilos_user` SET `name` = ?, `admin` = 1 WHERE `id` = ?', ['Written after archive', $userId]);
        $this->assertSame($archivedCounts['hilos_change_log'] + 1, $this->journalCounts()['hilos_change_log']);
        $phases = [];
        new BackupRestorer()->restore(
            self::ARCHIVE_ID,
            BackupScope::FULL,
            RestoreEnvDecision::REQUIRE_ANONYMIZATION,
            null,
            static function (RestorePhase $phase) use (&$phases): void {
                $phases[] = $phase;
            },
        );
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);

        $this->assertSame($archivedCounts, $this->journalCounts());
        $restoredRows = $this->journalRows();
        foreach ($archivedRows as $table => $rows) {
            $this->assertNotEmpty($rows, $table . ' must exercise real restore rows');
            foreach ($rows as $position => $row) {
                $expected = $row;
                foreach (self::LEGACY_PII[$table] as $column => $strategy) {
                    $expected[$column] = $strategy === AnonymizationStrategy::NULLIFY || $row[$column] === null
                        ? null : BackupConstants::ANONYMIZATION_MASK;
                }
                // Full row equality also pins every non-personal field, id and relationship.
                $this->assertSame($expected, $restoredRows[$table][$position], $table . ' row ' . $row['id']);
            }
        }
        $restoredRegistry = PiiRegistry::collect();
        foreach (self::LEGACY_PII as $table => $strategies) {
            $this->assertSame($strategies, $restoredRegistry->strategiesFor(ChangeLogDatabase::CONNECTION_INDEX, $table));
            $this->assertSame(self::LEGACY_NOT_PERSONAL[$table], $restoredRegistry->notPersonalColumns(ChangeLogDatabase::CONNECTION_INDEX, $table));
        }
        Database::sql('SELECT `name`, `admin` FROM `hilos_user` WHERE `id` = ?', [$userId]);
        $person = Database::row();
        $this->assertNotNull($person);
        $this->assertSame('User ' . $userId, $person['name']);
        $this->assertSame(0, (int)$person['admin']);
        $this->assertSame([
            RestorePhase::VERIFYING,
            RestorePhase::EXTRACTING,
            RestorePhase::IMPORTING,
            RestorePhase::MIGRATING,
            RestorePhase::ANONYMIZING,
            RestorePhase::INSTALLING_TRIGGERS,
        ], $phases);
        $this->assertSame(self::JOURNAL_TABLES, $this->tableNames(ChangeLogDatabase::configuredName()));
        $this->assertInstalledTriggersMatchFiles();

        Database::sql('UPDATE `hilos_user` SET `name` = ? WHERE `id` = ?', ['Written after restore', $userId]);
        $this->assertSame($archivedCounts['hilos_change_log'] + 1, $this->journalCounts()['hilos_change_log']);
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT l.`mutation_type`, t.`name` FROM {$journal}.`hilos_change_log` l"
            . " JOIN {$journal}.`hilos_change_log_table` t ON t.`id` = l.`table_id`"
            . ' WHERE l.`record_key` = JSON_ARRAY(?) ORDER BY l.`id` DESC LIMIT 1', [$userId]);
        $this->assertSame(['mutation_type' => 'update', 'name' => 'hilos_user'], Database::row());
    }

    /** A production archive predating retirement passes preflight and migrates before anonymization. */
    public function testFullProductionRestoreRetiresHistoricalModerationTable(): void
    {
        $this->assertSame(110, Migration::getCurrentIndex());
        $this->assertSame(1, Migration::migrateDown(109));
        Schema::reset();
        Database::sql('INSERT INTO `moderator_prompt_piece` (`section`, `prompt_piece`) VALUES (?, ?)',
            ['message_rule', 'Historical non-personal moderation rule']);
        Database::sql('INSERT INTO `hilos_user` (`name`) VALUES (?)', ['Historical archive person']);
        $userId = Database::lastInsertId();
        $this->seedEveryJournalField();
        $archivedCounts = $this->journalCounts();

        // Label only this test-data dump as production, then return to the test environment.
        $previousEnv = getenv('APP_ENV');
        try {
            putenv('APP_ENV=prod');
            $archive = new BackupCreator()->create(self::ARCHIVE_ID, BackupScope::FULL);
        } finally {
            $previousEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousEnv);
        }
        $this->assertSame('prod', $archive->env);
        $this->assertSame([0, 1], array_column($archive->connections, 'index'));
        $this->assertSame(109, $archive->connections[0]->migrationIndex);
        $this->assertNotNull($archive->sha256);
        $this->assertArchiveHasNoTriggers(BackupScope::FULL);
        $this->assertStringContainsString('CREATE TABLE `moderator_prompt_piece`',
            file_get_contents($this->storeRoot . '/unpacked/db-0.sql'));
        foreach (self::JOURNAL_TABLES as $table) {
            $this->assertStringContainsString('CREATE TABLE `' . $table . '`',
                file_get_contents($this->storeRoot . '/unpacked/db-1.sql'));
        }

        $this->assertSame(1, Migration::migrateUp());
        Database::sql('UPDATE `hilos_user` SET `name` = ? WHERE `id` = ?', ['Written after historical archive', $userId]);
        $decision = RestoreEnvGuard::decide(AppEnv::fromString($archive->env), AppEnv::TEST, false)->decision;
        $this->assertSame(RestoreEnvDecision::REQUIRE_ANONYMIZATION, $decision);
        new BackupRestorer()->restore(self::ARCHIVE_ID, BackupScope::FULL, $decision);
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);

        $this->assertSame(110, Migration::getCurrentIndex());
        $this->assertArrayNotHasKey('moderator_prompt_piece', LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX));
        $this->assertSame([], Database::sql('SELECT `key` FROM `hilos_setting` WHERE `key` IN (?, ?, ?, ?)', [
            'chat_moderation_model', 'chat_moderation_url', 'chat_moderation_provider', 'chat_moderation_timeout_sec',
        ])->rows());
        $this->assertSame('User ' . $userId, Database::sql('SELECT `name` FROM `hilos_user` WHERE `id` = ?', [$userId])->field('name'));
        $this->assertSame($archivedCounts, $this->journalCounts());
        $this->assertInstalledTriggersMatchFiles();
        AnonymizationStartupGuard::assertLiveSchemaClassified();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
    }

    /**
     * The journal receives neither migration bookkeeping nor rows on a schema restore.
     *
     * @throws HilosException When a dump, restore or schema query fails
     */
    public function testSchemaOnlyRestoreKeepsTheJournalEmptyAndUnmigrated(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`name`) VALUES (?)', ['Schema archive person']);
        $this->assertGreaterThan(0, $this->journalCounts()['hilos_change_log']);
        $archive = new BackupCreator()->create(self::ARCHIVE_ID, BackupScope::SCHEMA_ONLY);

        new BackupRestorer()->restore(self::ARCHIVE_ID, BackupScope::SCHEMA_ONLY, RestoreEnvDecision::REQUIRE_ANONYMIZATION);
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);

        $this->assertSame(self::JOURNAL_TABLES, $this->tableNames(ChangeLogDatabase::configuredName()));
        $this->assertSame(array_fill_keys(self::JOURNAL_TABLES, 0), $this->journalCounts());
        $this->assertSame($archive->connections[0]->migrationIndex, Migration::getCurrentIndex());
        $this->assertInstalledTriggersMatchFiles();
    }

    /**
     * A one-connection archive is refused before executing even its first SQL statement.
     *
     * @throws HilosException When fixture creation or the database snapshots fail
     * @throws JsonException When fixture metadata cannot be encoded
     */
    public function testArchiveWithoutJournalRefusesBeforeTouchingEitherDatabase(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`name`) VALUES (?)', ['Keep this person']);
        $this->publishArchiveWithoutJournal();
        $before = $this->databaseContents();
        $triggers = self::triggerCatalog();
        $phases = [];

        try {
            new BackupRestorer()->restore(
                self::ARCHIVE_ID,
                BackupScope::FULL,
                RestoreEnvDecision::ALLOW,
                null,
                static function (RestorePhase $phase) use (&$phases): void {
                    $phases[] = $phase;
                },
            );
            $this->fail('An archive without the journal must be refused');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString(
                'connection 1: the archive carries no change-log database; the journal cannot roll back with the data',
                $failure->getMessage(),
            );
        }

        $this->assertNotContains(RestorePhase::IMPORTING, $phases);
        $this->assertSame($before, $this->databaseContents());
        $this->assertSame($triggers, self::triggerCatalog());
    }

    /**
     * Capture the baseline before any fixture write or trigger installation.
     *
     * @throws HilosException When bootstrap, the baseline dump or trigger installation fails
     */
    protected function setUp(): void
    {
        parent::setUp();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->originalTriggers = self::triggerCatalog();
        $this->originalContents = $this->databaseContents();
        $this->storeRoot = sys_get_temp_dir() . '/hilos-chat-change-log-restore-' . getmypid();
        $this->previousBackupDir = getenv('BACKUP_DIR');
        putenv('BACKUP_DIR=' . $this->storeRoot);
        $this->baseline = new BackupCreator()->create(self::BASELINE_ID, BackupScope::FULL);
        JournalTriggerInstaller::apply();
    }

    /**
     * Return both databases and the live trigger set, including an originally empty set.
     *
     * @throws HilosException When restoring the baseline or original triggers fails
     */
    protected function tearDown(): void
    {
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            if ($this->baseline !== null) {
                new BackupRestorer()->restore($this->baseline->id, BackupScope::FULL, RestoreEnvDecision::ALLOW);
                Schema::reset(DatabaseConnectionDefaults::PRIMARY_INDEX);
                Schema::initialize(DatabaseConnectionDefaults::PRIMARY_INDEX);
                foreach (self::triggerCatalog() as $row) {
                    Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
                }
                foreach ($this->originalTriggers as $row) {
                    Database::sql('CREATE TRIGGER `' . $row['TRIGGER_NAME'] . '` ' . $row['ACTION_TIMING']
                        . ' ' . $row['EVENT_MANIPULATION'] . ' ON `' . $row['EVENT_OBJECT_TABLE']
                        . '` FOR EACH ROW ' . $row['ACTION_STATEMENT']);
                }
                $this->assertSame($this->originalTriggers, self::triggerCatalog());
                $this->assertSame($this->originalContents, $this->databaseContents(), 'Both databases must return to their original rows');
            }
        } finally {
            $this->previousBackupDir === false ? putenv('BACKUP_DIR') : putenv('BACKUP_DIR=' . $this->previousBackupDir);
            $this->removeTree($this->storeRoot);
            parent::tearDown();
        }
    }

    /** Fixture SQL supplies historical personal values that today's privacy-aware triggers never capture. */
    private function seedEveryJournalField(): void
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $at = '2026-10-20 12:34:56.654321';
        Database::sql("INSERT INTO {$journal}.`hilos_change_log_table` (`name`) VALUES ('restore_fixture')");
        $tableId = Database::lastInsertId();
        Database::sql("INSERT INTO {$journal}.`hilos_change_log_field` (`table_id`, `name`) VALUES (?, 'historical_field')", [$tableId]);
        $fieldId = Database::lastInsertId();
        Database::sql("INSERT INTO {$journal}.`hilos_change_log_receipt`"
            . ' (`created_at`, `actor_user_id`, `subject_user_id`, `session_id`, `channel`, `action`, `agent`, `source`, `open_handovers`)'
            . " VALUES (?, 12, 13, 14, 'web', 'restore.fixture', 'private-agent', 'private-source', 3)", [$at]);
        $receiptId = Database::lastInsertId();
        Database::sql("INSERT INTO {$journal}.`hilos_change_log`"
            . ' (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)'
            . " VALUES (?, ?, ?, 'private-key', ?, 'update')", [$at, $receiptId, $tableId, hash('sha256', 'private-key', true)]);
        $logId = Database::lastInsertId();
        foreach (['inline', 'long', 'fact'] as $kind) {
            Database::sql("INSERT INTO {$journal}.`hilos_change_log_change`"
                . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)'
                . ' VALUES (?, ?, ?, ?, 0, 1, ?, ?)', [$at, $logId, $fieldId, $kind,
                    $kind === 'fact' ? null : 'private-old', $kind === 'fact' ? null : 'private-new']);
            if ($kind === 'long') {
                $changeId = Database::lastInsertId();
                Database::sql("INSERT INTO {$journal}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)"
                    . ' VALUES (?, ?, ?, ?)', [$at, $changeId, str_repeat('old private ', 1000), str_repeat('new private ', 1000)]);
            }
        }
    }

    /** @return array<string, list<array<string, mixed>>> All journal columns in stable physical identity order */
    private function journalRows(): array
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $rows = [];
        foreach (self::JOURNAL_TABLES as $table) {
            Database::sql("SELECT * FROM {$journal}.`{$table}` ORDER BY `id`");
            $rows[$table] = Database::rows();
        }
        return $rows;
    }

    /** @return array<string, int> Row counts of all six journal tables */
    private function journalCounts(): array
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $counts = [];
        foreach (self::JOURNAL_TABLES as $table) {
            Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`{$table}`");
            $counts[$table] = (int)Database::field('count');
        }
        return $counts;
    }

    /**
     * @param string $database Database whose table names are read
     * @return list<string> Alphabetized base tables
     */
    private function tableNames(string $database): array
    {
        Database::sql('SELECT TABLE_NAME AS `name` FROM INFORMATION_SCHEMA.TABLES'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME', [$database, 'BASE TABLE']);
        return array_column(Database::rows(), 'name');
    }

    /** @return array<int, array<string, list<array<string, mixed>>>> Sorted rows of every table in the pair */
    private function databaseContents(): array
    {
        $contents = [];
        foreach ([DatabaseConnectionDefaults::PRIMARY_INDEX, ChangeLogDatabase::CONNECTION_INDEX] as $index) {
            $database = Database::getConnectionConfig($index)->database;
            foreach ($this->tableNames($database) as $table) {
                Database::sql('SELECT * FROM ' . ChangeLogDatabase::identifier($database) . '.`' . $table . '`');
                $rows = Database::rows();
                sort($rows);
                $contents[$index][$table] = $rows;
            }
        }
        return $contents;
    }

    private function assertInstalledTriggersMatchFiles(): void
    {
        $files = JournalTriggerFiles::readAll(JournalTriggerGenerator::plan(), Migration::getCurrentIndex());
        $live = self::triggerCatalog();
        $names = array_map(static fn (JournalTriggerFile $file): string => $file->name, $files);
        sort($names);
        $this->assertCount(18, $names);
        $this->assertSame($names, array_keys($live));
        foreach ($files as $file) {
            $this->assertTrue($file->matches($live[$file->name], ChangeLogDatabase::configuredName()), $file->name);
        }
    }

    /** @return array<string, array<string, mixed>> Primary schema trigger definitions keyed by name */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return array_column(Database::rows(), null, 'TRIGGER_NAME');
    }

    /** @param BackupScope $scope Scope of the archive to unpack */
    private function assertArchiveHasNoTriggers(BackupScope $scope): void
    {
        $archive = BackupRestorer::locateArchive($this->storeRoot, self::ARCHIVE_ID, $scope);
        $directory = $this->storeRoot . '/unpacked';
        mkdir($directory, 0700);
        exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($directory), $output, $exitCode);
        $this->assertSame(0, $exitCode, 'The real dump archive must unpack');
        foreach ([0, 1] as $index) {
            $path = $directory . '/db-' . $index . '.sql';
            $this->assertFileExists($path);
            $sql = file_get_contents($path);
            $this->assertIsString($sql);
            $this->assertStringNotContainsString('TRIGGER', strtoupper($sql), $path);
        }
    }

    private function publishArchiveWithoutJournal(): void
    {
        $directory = $this->storeRoot . '/build';
        mkdir($directory, 0700);
        file_put_contents($directory . '/db-0.sql', "UPDATE `hilos_user` SET `name` = 'Unexpected import';\n");
        $base = BackupCreator::archiveBaseName(self::ARCHIVE_ID, 'test', BackupScope::FULL);
        $archive = $this->storeRoot . '/' . BackupScope::FULL->value . '/' . $base . BackupCreator::ARCHIVE_EXTENSION;
        exec('tar -czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($directory) . ' .', $output, $exitCode);
        $this->assertSame(0, $exitCode, 'The one-connection fixture archive must build');
        $metadata = new BackupMetadata(
            id: self::ARCHIVE_ID,
            createdAt: '2026-10-10T00:00:01+00:00',
            env: 'test',
            scope: BackupScope::FULL,
            connections: [new BackupConnectionMeta(
                DatabaseConnectionDefaults::PRIMARY_INDEX,
                Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX)->database,
                Migration::getCurrentIndex(),
            )],
            sizeBytes: (int)filesize($archive),
            durationSeconds: 1,
            keep: false,
            status: BackupStatus::SUCCESS,
            sha256: (string)hash_file(BackupVerifier::DIGEST_ALGO, $archive),
        );
        file_put_contents(
            $this->storeRoot . '/' . BackupScope::FULL->value . '/' . $base . BackupCreator::SIDECAR_EXTENSION,
            json_encode($metadata->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /** @param string $path Temporary fixture path to remove recursively */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
