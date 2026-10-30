<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerColumnTypes;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournalColumnPlacement;
use Hilos\Database\Schema\Schema;

/** DDL runs outside rollback scopes, with the shared schema and trigger catalog restored afterward. */
final class ChatModerationRetirementMigrationTest extends IntegrationTestCase
{
    private const int MIGRATION_INDEX = 110;
    private const int BEFORE_RETIREMENT = 109;
    private const string LEGACY_TABLE = 'moderator_prompt_piece';

    private const array LEGACY_SETTINGS = [
        'chat_moderation_model' => 'string',
        'chat_moderation_url' => 'string',
        'chat_moderation_provider' => 'string',
        'chat_moderation_timeout_sec' => 'float',
    ];

    private const array PRESERVED_SETTINGS = [
        'moderation.text.model' => 'Retirement text model',
        'moderation.image.model' => 'Retirement image model',
        'chat_bot_model' => 'Retirement bot model',
        'chat_moderation_extra_fixture' => 'Unrelated setting with the same prefix',
    ];

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    private ?int $latestMigrationIndex = null;
    private ?int $ruleId = null;

    /** @var list<array<string, mixed>> Trigger catalog before the fixture installed current and legacy triggers */
    private array $originalTriggers = [];

    /** @var list<array<string, mixed>> Original values of settings populated by this fixture */
    private array $originalSettings = [];

    /** @var array<string, int> Journal high water marks before any fixture writes */
    private array $journalBaseline = [];

    /** Captures shared state before rewinding only the retirement migration. */
    protected function setUp(): void
    {
        parent::setUp();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->latestMigrationIndex = Migration::getCurrentIndex();
        $this->originalTriggers = self::triggerCatalog();
        $this->originalSettings = Database::sql(
            'SELECT * FROM `hilos_setting` WHERE `key` IN (?, ?, ?, ?) ORDER BY `id`',
            array_keys(self::PRESERVED_SETTINGS),
        )->rows();
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            $this->journalBaseline[$table] = (int)Database::sql(
                "SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$journal}.`{$table}`",
            )->field('id');
        }
    }

    /** Filled legacy data disappears; framework data and all pre-upgrade journal rows survive both upgrades. */
    public function testPopulatedLegacyUpgradeDownAndRepeatedUpgradePreserveFrameworkDataAndHistory(): void
    {
        self::assertGreaterThanOrEqual(self::MIGRATION_INDEX, $this->latestMigrationIndex);
        JournalTriggerInstaller::apply();
        self::assertSame($this->latestMigrationIndex - self::BEFORE_RETIREMENT, Migration::migrateDown(self::BEFORE_RETIREMENT));
        Schema::reset();
        $this->assertEmptyLegacyStructure();
        $this->assertNullableLegacySettings();
        $this->installLegacyTriggers();

        $legacyReceipt = JournalReceiptScope::run(JournalReceiptData::web(1627, null, 1627, 'legacy.moderation.fixture', 'library'),
            static function (): ?int {
                foreach (['name_rule', 'message_rule', 'photo_rule', 'message_picture_rule'] as $section) {
                    Database::sqlRun('INSERT INTO `moderator_prompt_piece` (`section`, `prompt_piece`) VALUES (?, ?)',
                        [$section, 'Legacy only: ' . $section]);
                }
                Database::sqlRun('UPDATE `moderator_prompt_piece` SET `prompt_piece` = ? WHERE `section` = ?',
                    [str_repeat('Legacy rule after. ', 400), 'message_rule']);
                return JournalReceiptScope::currentId();
            });
        self::assertNotNull($legacyReceipt);
        self::assertSame(4, (int)Database::sql('SELECT COUNT(*) AS `n` FROM `moderator_prompt_piece`')->field('n'));
        foreach (self::LEGACY_SETTINGS as $key => $type) {
            Database::sqlRun('UPDATE `hilos_setting` SET `value` = ? WHERE `key` = ?',
                [$type === 'float' ? '37.5' : 'Configured legacy ' . $key, $key]);
        }
        foreach (self::PRESERVED_SETTINGS as $key => $value) {
            Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, 'string', $value]);
        }
        Database::sql('INSERT INTO `hilos_moderation_rule` (`kind`, `rule`) VALUES (?, ?)',
            [ChatModerationKindKey::MESSAGE_PICTURE, 'Administrator rule survives retirement']);
        $this->ruleId = Database::lastInsertId();

        $rules = Database::sql('SELECT * FROM `hilos_moderation_rule` ORDER BY `id`')->rows();
        self::assertGreaterThanOrEqual(7, count($rules), 'The six framework seed rules and an administrator rule are populated');
        $settings = self::preservedSettingRows();
        $settingValues = array_column($settings, 'value', 'key');
        foreach (self::PRESERVED_SETTINGS as $key => $value) {
            self::assertSame($value, $settingValues[$key]);
        }
        $history = self::journalRows();
        self::assertContains(self::LEGACY_TABLE, array_column($history['hilos_change_log_table'], 'name'));
        self::assertNotEmpty($history['hilos_change_log_value'], 'The legacy update wrote a real long journal value');

        self::assertSame(1, Migration::migrateUp(self::MIGRATION_INDEX));
        $this->assertRetired();
        self::assertSame($rules, Database::sql('SELECT * FROM `hilos_moderation_rule` ORDER BY `id`')->rows());
        self::assertSame($settings, self::preservedSettingRows());
        $this->assertHistoryPreserved($history);
        self::assertSame($this->latestMigrationIndex - self::MIGRATION_INDEX, Migration::migrateUp($this->latestMigrationIndex));
        self::assertSame($this->latestMigrationIndex, Migration::getCurrentIndex());
        JournalTriggerInstaller::apply();

        self::assertSame($this->latestMigrationIndex - self::BEFORE_RETIREMENT, Migration::migrateDown(self::BEFORE_RETIREMENT));
        $this->assertEmptyLegacyStructure();
        $this->assertNullableLegacySettings();
        self::assertSame([], self::legacyTriggerNames());
        self::assertSame(1, Migration::migrateUp(self::MIGRATION_INDEX));
        $this->assertRetired();
        self::assertSame($rules, Database::sql('SELECT * FROM `hilos_moderation_rule` ORDER BY `id`')->rows());
        self::assertSame($settings, self::preservedSettingRows());
        $this->assertHistoryPreserved($history);
        self::assertSame($this->latestMigrationIndex - self::MIGRATION_INDEX, Migration::migrateUp($this->latestMigrationIndex));
        self::assertSame($this->latestMigrationIndex, Migration::getCurrentIndex());
        JournalTriggerInstaller::apply();
    }

    /** Restores the migration level, fixture data, journal and exact trigger catalog even after a failed assertion. */
    protected function tearDown(): void
    {
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::setJournalReceiptId(null);
            if ($this->latestMigrationIndex !== null) {
                Migration::migrateUp($this->latestMigrationIndex);
            }
            foreach (self::triggerCatalog() as $trigger) {
                Database::sql('DROP TRIGGER `' . $trigger['TRIGGER_NAME'] . '`');
            }
            Database::sqlRun('DELETE FROM `hilos_moderation_rule` WHERE `id` = ?', [$this->ruleId]);
            foreach (self::PRESERVED_SETTINGS as $key => $_value) {
                $original = array_column($this->originalSettings, null, 'key')[$key] ?? null;
                if ($original === null) {
                    Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` = ?', [$key]);
                } else {
                    Database::sqlRun('UPDATE `hilos_setting` SET `type` = ?, `value` = ? WHERE `key` = ?',
                        [$original['type'], $original['value'], $key]);
                }
            }
            $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
            foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
                Database::sqlRun("DELETE FROM {$journal}.`{$table}` WHERE `id` > ?", [$this->journalBaseline[$table]]);
            }
            foreach ($this->originalTriggers as $trigger) {
                Database::sql('CREATE TRIGGER `' . $trigger['TRIGGER_NAME'] . '` ' . $trigger['ACTION_TIMING']
                    . ' ' . $trigger['EVENT_MANIPULATION'] . ' ON `' . $trigger['EVENT_OBJECT_TABLE']
                    . '` FOR EACH ROW ' . $trigger['ACTION_STATEMENT']);
            }
            Schema::reset();
            Schema::initialize();
            Hilos::$db->forgetLoadedDataForTest();
        } finally {
            parent::tearDown();
        }
    }

    /** Recreates the retired trigger contract only on this migration fixture's legacy schema. */
    private function installLegacyTriggers(): void
    {
        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $files = JournalTriggerRenderer::render($schemas[self::LEGACY_TABLE], [
            'id' => new JournalColumnPlacement(JournalColumnMode::RECORD_KEY),
            'section' => new JournalColumnPlacement(JournalColumnMode::VALUE),
            'prompt_piece' => new JournalColumnPlacement(JournalColumnMode::VALUE),
        ], JournalTriggerColumnTypes::read(DatabaseConnectionDefaults::PRIMARY_INDEX), self::BEFORE_RETIREMENT);
        foreach ($files as $file) {
            Database::sql($file->sql(ChangeLogDatabase::configuredName()));
        }
        self::assertSame([
            'hilos_cl_moderator_prompt_piece_after_delete',
            'hilos_cl_moderator_prompt_piece_after_insert',
            'hilos_cl_moderator_prompt_piece_after_update',
        ], self::legacyTriggerNames());
    }

    /** DOWN recovers only the original empty table, with its unsigned auto-increment key and four sections. */
    private function assertEmptyLegacyStructure(): void
    {
        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
        self::assertArrayHasKey(self::LEGACY_TABLE, $schemas);
        self::assertSame(['id'], $schemas[self::LEGACY_TABLE]->primaryKey);
        self::assertSame(['id' => false, 'section' => false, 'prompt_piece' => false], $schemas[self::LEGACY_TABLE]->columns);
        $columns = Database::sql('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, EXTRA FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [self::LEGACY_TABLE])->rows();
        self::assertSame(['int', 'enum', 'text'], array_column($columns, 'DATA_TYPE'));
        self::assertStringContainsString('unsigned', $columns[0]['COLUMN_TYPE']);
        self::assertSame('auto_increment', $columns[0]['EXTRA']);
        self::assertSame("enum('name_rule','message_rule','photo_rule','message_picture_rule')", $columns[1]['COLUMN_TYPE']);
        self::assertSame([], Database::sql('SELECT * FROM `moderator_prompt_piece`')->rows());
    }

    /** DOWN brings back nullable overrides, without any of the retired configured values. */
    private function assertNullableLegacySettings(): void
    {
        $rows = Database::sql('SELECT `key`, `type`, `value` FROM `hilos_setting` WHERE `key` IN (?, ?, ?, ?) ORDER BY `key`',
            array_keys(self::LEGACY_SETTINGS))->rows();
        $expected = [];
        foreach (self::LEGACY_SETTINGS as $key => $type) {
            $expected[] = ['key' => $key, 'type' => $type, 'value' => null];
        }
        usort($expected, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        self::assertSame($expected, $rows);
    }

    /** UP leaves neither the legacy table, its triggers nor its four exact settings keys. */
    private function assertRetired(): void
    {
        Schema::reset();
        Schema::initialize();
        self::assertSame(self::MIGRATION_INDEX, Migration::getCurrentIndex());
        self::assertArrayNotHasKey(self::LEGACY_TABLE, LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX));
        self::assertSame([], self::legacyTriggerNames());
        self::assertSame([], Database::sql('SELECT `key` FROM `hilos_setting` WHERE `key` IN (?, ?, ?, ?)',
            array_keys(self::LEGACY_SETTINGS))->rows());
    }

    /** @return list<array<string, mixed>> All settings beyond the four retired keys, including ids and configured values */
    private static function preservedSettingRows(): array
    {
        return Database::sql('SELECT * FROM `hilos_setting` WHERE `key` NOT IN (?, ?, ?, ?) ORDER BY `id`',
            array_keys(self::LEGACY_SETTINGS))->rows();
    }

    /** @return array<string, list<array<string, mixed>>> Complete journal snapshot, including legacy dictionary and blob rows */
    private static function journalRows(): array
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $history = [];
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            $history[$table] = Database::sql("SELECT * FROM {$journal}.`{$table}` ORDER BY `id`")->rows();
        }
        return $history;
    }

    /** @param array<string, list<array<string, mixed>>> $history Journal state before UP */
    private function assertHistoryPreserved(array $history): void
    {
        foreach (self::journalRows() as $table => $rows) {
            $lastId = $history[$table] === [] ? 0 : (int)$history[$table][array_key_last($history[$table])]['id'];
            self::assertSame($history[$table], array_values(array_filter($rows,
                static fn (array $row): bool => (int)$row['id'] <= $lastId)), $table . ' preserves every historical row');
        }
    }

    /** @return list<string> Installed triggers of the retired table */
    private static function legacyTriggerNames(): array
    {
        return array_column(Database::sql('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS'
            . ' WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? ORDER BY TRIGGER_NAME', [self::LEGACY_TABLE])->rows(),
            'TRIGGER_NAME');
    }

    /** @return list<array<string, mixed>> Primary schema trigger definitions */
    private static function triggerCatalog(): array
    {
        return Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME')->rows();
    }
}
