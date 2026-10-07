<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalTriggerColumnTypes;
use Hilos\Database\ChangeLog\JournalTriggerFile;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerGenerator;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournalColumnPlacement;

/** Exercises generated SQL on mixed column modes; startup reconciliation has its own integration suite. */
final class JournalTriggerGeneratorIntegrationTest extends IntegrationTestCase
{
    private const string FIXTURE_TABLE = 'hilos_cl_fixture';
    private const array JOURNAL_TABLES_IN_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Highest journal id before this test writes */
    private static array $journalBaseline = [];

    /**
     * Exercise the actual MariaDB trigger bodies, their journal rows and transaction boundary.
     */
    public function testGeneratedTriggersJournalValuesAndRollBackWithSourceWrites(): void
    {
        $files = [];
        $projectPath = dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers';
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath($projectPath);
        self::$journalBaseline = self::snapshotJournalIds();
        try {
            Database::sqlRun('DELETE FROM `hilos_identity` WHERE `identifier` = ?', ['journal-fixture@example.test']);
            Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` = ?', ['journal.fixture']);
            $files = JournalTriggerGenerator::plan();
            $this->assertCount(12, $files);
            $this->assertSame([], JournalTriggerFiles::write($files));
            foreach ($files as $file) {
                $this->assertSame($file->content(), file_get_contents($projectPath . '/' . $file->name . '.sql'));
                self::install($file);
            }

            Database::sqlRun('CREATE TABLE `' . self::FIXTURE_TABLE . '` ('
                . '`id` INT NOT NULL, `part` VARCHAR(20) NOT NULL, `short` VARCHAR(20) NULL,'
                . '`long_text` TEXT NULL, `json_value` JSON NULL, `personal` VARCHAR(20) NULL,'
                . '`secret` VARCHAR(20) NULL, `noise` INT NULL, `binary_data` VARBINARY(16) NULL,'
                . 'PRIMARY KEY (`id`, `part`)) ENGINE=InnoDB');
            $schema = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX)[self::FIXTURE_TABLE];
            $fixtureFiles = JournalTriggerRenderer::render($schema, self::fixturePlacements(),
                JournalTriggerColumnTypes::read(DatabaseConnectionDefaults::PRIMARY_INDEX), 85);
            foreach ($fixtureFiles as $file) {
                $files[] = $file;
                self::install($file);
            }

            $this->assertFixtureJournal();
            $this->assertFrameworkJournal();
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sqlRun('SET @hilos_receipt = NULL');
            Database::sqlRun('DELETE FROM `hilos_identity` WHERE `identifier` = ?', ['journal-fixture@example.test']);
            Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` = ?', ['journal.fixture']);
            foreach ($files as $file) {
                Database::sqlRun('DROP TRIGGER IF EXISTS `' . $file->name . '`');
            }
            Database::sqlRun('DROP TABLE IF EXISTS `' . self::FIXTURE_TABLE . '`');
            self::removeTestJournalRows();
        }
    }

    /**
     * @param JournalTriggerFile $file Trigger to install on the test database
     */
    private static function install(JournalTriggerFile $file): void
    {
        Database::sqlRun('DROP TRIGGER IF EXISTS `' . $file->name . '`');
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sqlRun(str_replace('{{change_log_database}}', $database, $file->body));
    }

    /**
     * @return array<string, JournalColumnPlacement> Fixture's deliberately mixed placements
     */
    private static function fixturePlacements(): array
    {
        return [
            'id' => new JournalColumnPlacement(JournalColumnMode::RECORD_KEY),
            'part' => new JournalColumnPlacement(JournalColumnMode::RECORD_KEY),
            'short' => new JournalColumnPlacement(JournalColumnMode::VALUE),
            'long_text' => new JournalColumnPlacement(JournalColumnMode::VALUE),
            'json_value' => new JournalColumnPlacement(JournalColumnMode::VALUE),
            'personal' => new JournalColumnPlacement(JournalColumnMode::PERSONAL),
            'secret' => new JournalColumnPlacement(JournalColumnMode::SECRET),
            'noise' => new JournalColumnPlacement(JournalColumnMode::NOISE, 'fixture counter'),
            'binary_data' => new JournalColumnPlacement(JournalColumnMode::BINARY),
        ];
    }

    private function assertFixtureJournal(): void
    {
        Database::sqlRun('SET @hilos_receipt = NULL');
        Database::sqlRun('INSERT INTO `' . self::FIXTURE_TABLE . '` '
            . '(`id`, `part`, `short`, `long_text`, `json_value`, `personal`, `secret`, `noise`, `binary_data`) '
            . "VALUES (1, 'Alpha', 'aB', 'old text', '{\"a\":1}', 'private-old', 'secret-old', 1, X'01')");
        $logs = self::fixtureLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('create', $logs[0]['mutation_type']);
        $this->assertNull($logs[0]['receipt_id']);
        $this->assertSame('[1, "Alpha"]', $logs[0]['record_key']);
        $this->assertSame(hash('sha256', $logs[0]['record_key']), bin2hex($logs[0]['record_key_hash']));

        Database::sqlRun('UPDATE `' . self::FIXTURE_TABLE . '` SET `noise` = 2 WHERE `id` = 1');
        Database::sqlRun("UPDATE `" . self::FIXTURE_TABLE . "` SET `secret` = 'secret-new' WHERE `id` = 1");
        Database::sqlRun('UPDATE `' . self::FIXTURE_TABLE . '` SET `noise` = `noise` WHERE `id` = 1');
        $this->assertCount(1, self::fixtureLogs());

        Database::sqlRun('SET @hilos_receipt = 777');
        Database::sqlRun('UPDATE `' . self::FIXTURE_TABLE . '` SET '
            . "`short` = 'ab ', `long_text` = 'new text', `json_value` = '{\"a\":2}',"
            . "`personal` = 'private-new', `binary_data` = X'02' WHERE `id` = 1");
        $logs = self::fixtureLogs();
        $this->assertCount(2, $logs);
        $this->assertSame('update', $logs[1]['mutation_type']);
        $this->assertSame('777', (string)$logs[1]['receipt_id']);
        $changes = self::changes((int)$logs[1]['id']);
        $this->assertSame(['binary_data', 'json_value', 'long_text', 'personal', 'short'], array_keys($changes));
        $this->assertSame(['inline', 'aB', 'ab '], [
            $changes['short']['kind'], $changes['short']['old_value'], $changes['short']['new_value'],
        ]);
        $this->assertSame('fact', $changes['personal']['kind']);
        $this->assertNull($changes['personal']['old_value']);
        $this->assertNull($changes['personal']['new_value']);
        $this->assertSame('fact', $changes['binary_data']['kind']);
        $this->assertSame('long', $changes['long_text']['kind']);
        $longValues = self::journalRows('SELECT `old_value`, `new_value` FROM `hilos_change_log_value` WHERE `change_id` = ?',
            [(int)$changes['long_text']['id']]);
        $this->assertSame([['old_value' => 'old text', 'new_value' => 'new text']], $longValues);
        $this->assertSame($logs[1]['created_at'], $changes['long_text']['created_at']);
        $this->assertSame('long', $changes['json_value']['kind']);
        $jsonValues = self::journalRows('SELECT `old_value`, `new_value` FROM `hilos_change_log_value` WHERE `change_id` = ?',
            [(int)$changes['json_value']['id']]);
        $this->assertSame([['old_value' => '{"a":1}', 'new_value' => '{"a":2}']], $jsonValues);

        Database::sqlRun("UPDATE `" . self::FIXTURE_TABLE . "` SET `part` = 'Beta' WHERE `id` = 1");
        $logs = self::fixtureLogs();
        $this->assertSame(['create', 'update', 'delete', 'create'], array_column($logs, 'mutation_type'));
        $this->assertSame($logs[2]['created_at'], $logs[3]['created_at']);
        $this->assertSame('[1, "Alpha"]', $logs[2]['record_key']);
        $this->assertSame('[1, "Beta"]', $logs[3]['record_key']);

        Database::sqlRun('SET @hilos_receipt = NULL');
        Database::sqlRun('DELETE FROM `' . self::FIXTURE_TABLE . '` WHERE `id` = 1');
        $logs = self::fixtureLogs();
        $this->assertSame('delete', $logs[4]['mutation_type']);
        $this->assertNull($logs[4]['receipt_id']);
        $deleted = self::changes((int)$logs[4]['id']);
        $this->assertSame('0', (string)$deleted['personal']['new_present']);
        $this->assertNull($deleted['personal']['old_value']);
        $this->assertNull($deleted['personal']['new_value']);

        Database::transactionStart();
        try {
            Database::sqlRun("INSERT INTO `" . self::FIXTURE_TABLE . "` (`id`, `part`) VALUES (2, 'rollback')");
            $this->assertSame(6, self::fixtureLogCountOnPrimary());
        } finally {
            Database::transactionRollback();
        }
        $this->assertSame(5, self::fixtureLogCountOnPrimary());
        $this->assertSame([], self::journalRows('SELECT `id` FROM `hilos_change_log_table` WHERE `name` = ?',
            ['not_a_table']));
    }

    private function assertFrameworkJournal(): void
    {
        Database::sqlRun('SET @hilos_receipt = NULL');
        Database::sql('SELECT MIN(`id`) AS `id` FROM `hilos_user`');
        $userId = (int)Database::field('id');
        $this->assertGreaterThan(0, $userId);
        Database::sql("INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `secret`)"
            . " VALUES (?, 'password', 'journal-fixture@example.test', 'hash-one')", [$userId]);
        $insertId = Database::lastInsertId();
        Database::sql('SELECT `id` FROM `hilos_identity` WHERE `identifier` = ?', ['journal-fixture@example.test']);
        $identityId = (int)Database::field('id');
        $this->assertSame($identityId, $insertId, 'A trigger must preserve the source INSERT id');
        Database::sqlRun('UPDATE `hilos_identity` SET `secret` = ? WHERE `id` = ?', ['hash-two', $identityId]);
        $identityLogs = self::tableLogs('hilos_identity');
        $this->assertSame(['create'], array_column($identityLogs, 'mutation_type'));
        Database::sqlRun('DELETE FROM `hilos_identity` WHERE `id` = ?', [$identityId]);
        $identityLogs = self::tableLogs('hilos_identity');
        $this->assertSame(['create', 'delete'], array_column($identityLogs, 'mutation_type'));
        $identityChanges = self::changes((int)$identityLogs[1]['id']);
        $this->assertArrayNotHasKey('secret', $identityChanges);
        $this->assertSame('1', (string)$identityChanges['provider']['old_present']);
        $this->assertSame('0', (string)$identityChanges['provider']['new_present']);
        $this->assertNull($identityChanges['provider']['old_value']);

        Database::sql('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            ['journal.fixture', 'string', 'before']);
        $insertId = Database::lastInsertId();
        Database::sql('SELECT `id` FROM `hilos_setting` WHERE `key` = ?', ['journal.fixture']);
        $settingId = (int)Database::field('id');
        $this->assertSame($settingId, $insertId, 'A trigger must preserve the source INSERT id');
        Database::sqlRun('UPDATE `hilos_setting` SET `value` = ? WHERE `id` = ?', ['after', $settingId]);
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `id` = ?', [$settingId]);
        $settingLogs = self::tableLogs('hilos_setting');
        $this->assertSame(['create', 'update', 'delete'], array_column($settingLogs, 'mutation_type'));
        $changes = self::changes((int)$settingLogs[1]['id']);
        $this->assertSame('fact', $changes['value']['kind']);
    }

    /**
     * @return list<array<string, mixed>> Fixture log rows
     */
    private static function fixtureLogs(): array
    {
        return self::journalRows('SELECT l.* FROM `hilos_change_log` l '
            . 'JOIN `hilos_change_log_table` t ON t.`id` = l.`table_id` '
            . 'WHERE t.`name` = ? AND l.`id` > ? ORDER BY l.`id`',
            [self::FIXTURE_TABLE, self::$journalBaseline['hilos_change_log']]);
    }

    /**
     * @param string $table Journaled source table
     * @return list<array<string, mixed>> This test's log rows for that table
     */
    private static function tableLogs(string $table): array
    {
        return self::journalRows('SELECT l.`id`, l.`mutation_type` FROM `hilos_change_log` l '
            . 'JOIN `hilos_change_log_table` t ON t.`id` = l.`table_id` '
            . 'WHERE t.`name` = ? AND l.`id` > ? ORDER BY l.`id`',
            [$table, self::$journalBaseline['hilos_change_log']]);
    }

    /**
     * Read through the writing connection so uncommitted trigger rows remain visible.
     *
     * @return int Rows in the fixture's journal
     */
    private static function fixtureLogCountOnPrimary(): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql('SELECT COUNT(*) AS `count` FROM ' . $database . '.`hilos_change_log` l '
            . 'JOIN ' . $database . '.`hilos_change_log_table` t ON t.`id` = l.`table_id` '
            . 'WHERE t.`name` = ? AND l.`id` > ?',
            [self::FIXTURE_TABLE, self::$journalBaseline['hilos_change_log']]);
        return (int)Database::field('count');
    }

    /**
     * @return array<string, int> Highest id of each journal table before this test
     */
    private static function snapshotJournalIds(): array
    {
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            $ids = [];
            foreach (self::JOURNAL_TABLES_IN_CLEANUP_ORDER as $table) {
                Database::sql('SELECT COALESCE(MAX(`id`), 0) AS `last_id` FROM `' . $table . '`');
                $ids[$table] = (int)Database::field('last_id');
            }
            return $ids;
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    private static function removeTestJournalRows(): void
    {
        if (self::$journalBaseline === []) {
            return;
        }
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            foreach (self::JOURNAL_TABLES_IN_CLEANUP_ORDER as $table) {
                Database::sqlRun('DELETE FROM `' . $table . '` WHERE `id` > ?', [self::$journalBaseline[$table]]);
            }
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * @param int $logId Journal row number
     * @return array<string, array<string, mixed>> Change rows keyed by column name
     */
    private static function changes(int $logId): array
    {
        $rows = self::journalRows('SELECT c.*, f.`name` FROM `hilos_change_log_change` c '
            . 'JOIN `hilos_change_log_field` f ON f.`id` = c.`field_id` '
            . 'WHERE c.`log_id` = ? ORDER BY f.`name`', [$logId]);
        $changes = [];
        foreach ($rows as $row) {
            $changes[$row['name']] = $row;
        }
        return $changes;
    }

    /**
     * @param string $sql Query on the journal database
     * @param list<mixed> $params SQL parameters
     * @return list<array<string, mixed>> Query rows
     */
    private static function journalRows(string $sql, array $params = []): array
    {
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Database::sql($sql, $params);
            return Database::rows();
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }
}
