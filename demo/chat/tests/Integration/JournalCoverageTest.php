<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Database as ChatDatabase;
use Demo\Chat\Database\Entity\Item\Bot;
use Demo\Chat\Database\Entity\Item\ModeratorPromptPiece;
use Demo\Chat\Hilos;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\ChangeLog\ChangeLogSectionReader;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryFilter;
use Hilos\Database\Migration;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Identity;
use Hilos\Database\Entity\Item\SecondFactor;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\Exception\UnplacedJournalColumnException;
use Hilos\Database\Schema\JournalColumnPolicy;
use Hilos\Database\Schema\JournalCoverageGuard;
use Hilos\Database\Schema\JournaledTables;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Hilos as HilosFacade;

/** The chat migration schema satisfies the Entity declarations the daemon judges. */
final class JournalCoverageTest extends IntegrationTestCase
{
    /** Direct CLI/worker initialization connects the journal even when facade initialization is deferred. */
    public function testDeferredFacadeInitializationLeavesPrimarySelected(): void
    {
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        ChatDatabase::initialize(initHilos: false);
        $this->assertTrue(Database::isConnected(DatabaseConnectionDefaults::PRIMARY_INDEX));
        $this->assertTrue(Database::isConnected(ChangeLogDatabase::CONNECTION_INDEX));
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
        $this->assertSame(ChangeLogDatabase::configuredName(), Database::getConnectionConfig(ChangeLogDatabase::CONNECTION_INDEX)->database);
    }

    public function testExactlySixConfigurationTablesPassOnTheLiveSchema(): void
    {
        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $pii = PiiRegistry::collect();
        $mounted = JournaledTables::mounted();

        $names = array_map(static fn(string $entity): string => $entity::_table, array_values($mounted));
        sort($names, SORT_STRING);
        $this->assertSame([
            'bot', 'hilos_identity', 'hilos_second_factor', 'hilos_setting', 'hilos_user', 'moderator_prompt_piece',
        ], $names);
        $this->assertNotContains('event', $names);
        $this->assertNotContains('event_message', $names);
        foreach ([User::class, Identity::class, SecondFactor::class, Setting::class, Bot::class, ModeratorPromptPiece::class] as $entityClass) {
            $this->assertContains($entityClass, $mounted);
            $this->assertArrayHasKey($entityClass::_table, $schemas);
            $placements = JournalColumnPolicy::forTable($entityClass, $schemas[$entityClass::_table], $pii);
            $this->assertCount(count($schemas[$entityClass::_table]->columns), $placements);
            if ($entityClass === Bot::class || $entityClass === ModeratorPromptPiece::class) {
                foreach ($placements as $column => $placement) {
                    $this->assertSame($column === $entityClass::_primary
                        ? JournalColumnMode::RECORD_KEY : JournalColumnMode::VALUE, $placement->mode);
                }
            }
        }

        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            JournalCoverageGuard::assertMountedTablesPlaced();
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    public function testProjectConfigurationCrudHasReceiptsAndCompleteValues(): void
    {
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        Database::sql('SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
        $originalTriggers = Database::rows();
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $cleanupOrder = [
            'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
            'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
        ];
        $baseline = [];
        foreach ($cleanupOrder as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS id FROM {$journal}.`{$table}`");
            $baseline[$table] = (int)Database::field('id');
        }
        $botId = null;
        $pieceId = null;
        try {
            JournalTriggerInstaller::apply();
            $receiptId = null;
            JournalReceiptScope::run(JournalReceiptData::web(1453, null, 1453, 'configuration.crud', 'test-agent'),
                function () use (&$botId, &$pieceId, &$receiptId): void {
                    $receiptId = JournalReceiptScope::currentId();
                    $botId = Hilos::$db->bots->actions->create('Journal bot', description: 'Initial description')->id;
                    $pieceId = Hilos::$db->moderatorPromptPieces->actions->create('message_rule', 'Initial prompt')->id;
                    Hilos::$db->bots[$botId]->actions->update(name: 'Journal bot renamed');
                    Hilos::$db->moderatorPromptPieces[$pieceId]->actions->update(promptPiece: str_repeat('Long prompt. ', 600));
                    Hilos::$db->bots[$botId]->actions->delete();
                    Hilos::$db->moderatorPromptPieces[$pieceId]->actions->delete();
                });
            $this->assertNotNull($receiptId);
            $reader = new ChangeLogSectionReader();
            foreach (['bot' => $botId, 'moderator_prompt_piece' => $pieceId] as $table => $id) {
                $history = $reader->historyWindow($table, new ChangeLogHistoryFilter(recordKey: [$id]),
                    false, null, TableAnchorDirection::After, 10);
                $this->assertCount(3, $history);
                $this->assertSame(['create', 'update', 'delete'], array_map(static fn($row): string => $row->entry->mutation, $history));
                foreach ($history as $row) {
                    $this->assertSame($receiptId, $row->entry->receiptId);
                    $this->assertSame('configuration.crud', $row->receipt->action);
                    $this->assertSame([$id], $row->entry->recordKey);
                }
                $this->assertSame([], $history[0]->entry->changes);
                $update = $reader->entry($history[1]->entry->id);
                $this->assertNotNull($update);
                $this->assertCount(1, $update->changes);
                $changed = $update->changes[0];
                $this->assertTrue($changed->oldPresent);
                $this->assertTrue($changed->newPresent);
                $delete = $reader->entry($history[2]->entry->id);
                $this->assertNotNull($delete);
                $snapshot = [];
                foreach ($delete->changes as $change) {
                    $snapshot[$change->field] = $change;
                    $this->assertTrue($change->oldPresent);
                    $this->assertFalse($change->newPresent);
                    $this->assertNull($change->newValue);
                }
                if ($table === 'bot') {
                    $this->assertSame('name', $changed->field);
                    $this->assertSame('Journal bot', $changed->oldValue);
                    $this->assertSame('Journal bot renamed', $changed->newValue);
                    $fields = array_values(array_diff(Bot::_columns, [Bot::id]));
                    $this->assertSame($fields, array_keys($snapshot));
                    $this->assertSame('Journal bot renamed', $snapshot['name']->oldValue);
                    $this->assertSame('Initial description', $snapshot['description']->oldValue);
                    $this->assertSame('80', $snapshot['reaction_chance']->oldValue);
                } else {
                    $this->assertSame('prompt_piece', $changed->field);
                    $this->assertSame('long', $changed->kind);
                    $this->assertFalse($changed->bodyOmitted);
                    $this->assertSame('Initial prompt', $changed->oldValue);
                    $this->assertSame(str_repeat('Long prompt. ', 600), $changed->newValue);
                    $this->assertSame(['section', 'prompt_piece'], array_keys($snapshot));
                    $this->assertSame('message_rule', $snapshot['section']->oldValue);
                    $this->assertSame('long', $snapshot['prompt_piece']->kind);
                    $this->assertSame($changed->newValue, $snapshot['prompt_piece']->oldValue);
                }
            }
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::setJournalReceiptId(null);
            Database::sql('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
            foreach (Database::rows() as $trigger) {
                Database::sql('DROP TRIGGER `' . $trigger['TRIGGER_NAME'] . '`');
            }
            Hilos::$db->bots[$botId]?->actions->delete();
            Hilos::$db->moderatorPromptPieces[$pieceId]?->actions->delete();
            foreach ($cleanupOrder as $table) {
                Database::sqlRun("DELETE FROM {$journal}.`{$table}` WHERE `id` > ?", [$baseline[$table]]);
            }
            foreach ($originalTriggers as $trigger) {
                Database::sql('CREATE TRIGGER `' . $trigger['TRIGGER_NAME'] . '` ' . $trigger['ACTION_TIMING']
                    . ' ' . $trigger['EVENT_MANIPULATION'] . ' ON `' . $trigger['EVENT_OBJECT_TABLE']
                    . '` FOR EACH ROW ' . $trigger['ACTION_STATEMENT']);
            }
        }
    }

    public function testAllUnclassifiedSqlColumnsAreNamedBeforeStartup(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::sqlRun('ALTER TABLE `hilos_setting` ADD COLUMN `journal_coverage_probe` INT NULL');
        try {
            Database::sqlRun('ALTER TABLE `hilos_user` ADD COLUMN `journal_coverage_probe` INT NULL');
            try {
                try {
                    JournalCoverageGuard::assertMountedTablesPlaced();
                    $this->fail('The startup guard must reject DB-only columns without verdicts');
                } catch (UnplacedJournalColumnException $failure) {
                    $this->assertStringContainsString('hilos_setting.journal_coverage_probe', $failure->getMessage());
                    $this->assertStringContainsString('hilos_user.journal_coverage_probe', $failure->getMessage());
                }
                $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
            } finally {
                Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                Database::sqlRun('ALTER TABLE `hilos_user` DROP COLUMN `journal_coverage_probe`');
            }
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sqlRun('ALTER TABLE `hilos_setting` DROP COLUMN `journal_coverage_probe`');
        }
    }

    public function testAnOptionalJournaledTableAbsentFromTheSchemaIsSkipped(): void
    {
        $original = HilosFacade::$db;
        HilosFacade::$db = new OptionalJournalDbContext();
        HilosFacade::$db->configure();
        try {
            $this->assertSame([OptionalJournalObjects::class => OptionalJournalEntity::class], JournaledTables::mounted());
            JournalCoverageGuard::assertMountedTablesPlaced();
        } finally {
            HilosFacade::$db = $original;
        }
    }
}

final class OptionalJournalDbContext extends HilosDbContext
{
    public function configure(): void
    {
        $this->_objectCollections[OptionalJournalObjects::class]
            = OptionalJournalObjects::initDB(Objects::LAZY_STRATEGY_KEY);
    }
}

final class OptionalJournalEntity extends Entity
{
    public const string _table = 'optional_journal_table';
    public const bool _journaled = true;
    public const array _pii = [];
    public const array _piiNotPersonal = [];
}

/** @extends Object_<OptionalJournalEntity> */
final class OptionalJournalObject extends Object_
{
    public const string ENTITY_CLASS = OptionalJournalEntity::class;
}

/** @extends Objects<OptionalJournalObject> */
final class OptionalJournalObjects extends Objects
{
    public const string OBJECT_CLASS = OptionalJournalObject::class;
}
