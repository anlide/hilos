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
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryRow;
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
use Hilos\Database\Entity\Item\ModerationRule;
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
    /** Journal tables in the order their rows are removed, children before parents. */
    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** Zone the first write of a person's browser memory carries. */
    private const string FIRST_BROWSER_ZONE = 'America/New_York';

    /** Zone the changing write of a person's browser memory carries. */
    private const string SECOND_BROWSER_ZONE = 'Asia/Tokyo';

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

    public function testExactlySevenConfigurationTablesPassOnTheLiveSchema(): void
    {
        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $pii = PiiRegistry::collect();
        $mounted = JournaledTables::mounted();

        $names = array_map(static fn(string $entity): string => $entity::_table, array_values($mounted));
        sort($names, SORT_STRING);
        $this->assertSame([
            'bot', 'hilos_identity', 'hilos_moderation_rule', 'hilos_second_factor', 'hilos_setting', 'hilos_user',
            'moderator_prompt_piece',
        ], $names);
        $this->assertNotContains('event', $names);
        $this->assertNotContains('event_message', $names);
        foreach ([
            User::class, Identity::class, SecondFactor::class, Setting::class, ModerationRule::class, Bot::class, ModeratorPromptPiece::class,
        ] as $entityClass) {
            $this->assertContains($entityClass, $mounted);
            $this->assertArrayHasKey($entityClass::_table, $schemas);
            $placements = JournalColumnPolicy::forTable($entityClass, $schemas[$entityClass::_table], $pii);
            $this->assertCount(count($schemas[$entityClass::_table]->columns), $placements);
            if (in_array($entityClass, [ModerationRule::class, Bot::class, ModeratorPromptPiece::class], true)) {
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
        $scratch = $this->openJournalScratch();
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
                $update = Hilos::$db->changeLogEntries->sectionEntry($history[1]->entry->id);
                $this->assertNotNull($update);
                $this->assertCount(1, $update->changes);
                $changed = $update->changes[0];
                $this->assertTrue($changed->oldPresent);
                $this->assertTrue($changed->newPresent);
                $delete = Hilos::$db->changeLogEntries->sectionEntry($history[2]->entry->id);
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
            $this->closeJournalScratch($scratch, static function () use (&$botId, &$pieceId): void {
                if (isset(Hilos::$db->bots[$botId])) {
                    Hilos::$db->bots[$botId]->actions->delete();
                }
                if (isset(Hilos::$db->moderatorPromptPieces[$pieceId])) {
                    Hilos::$db->moderatorPromptPieces[$pieceId]->actions->delete();
                }
            });
        }
    }

    /**
     * A person's last browser time zone is a personal column: its change is journaled as the fact of the
     * change, an equal write leaves no record, and neither the entry nor the receipt carries the zone (HIL-1582).
     */
    public function testLastBrowserTimeZoneOfAPersonIsJournaledAsAFactOnly(): void
    {
        $scratch = $this->openJournalScratch();
        $personId = null;
        try {
            JournalTriggerInstaller::apply();
            $firstReceiptId = null;
            JournalReceiptScope::run(JournalReceiptData::web(1453, null, 1453, 'person.zone.first', 'test-agent'),
                function () use (&$personId, &$firstReceiptId): void {
                    $firstReceiptId = JournalReceiptScope::currentId();
                    $personId = (int)Hilos::$db->users->actions->createWithName('Journal zone person')->id;
                    Hilos::$db->users[$personId]->actions->setLastBrowserTimeZone(self::FIRST_BROWSER_ZONE);
                });
            $this->assertNotNull($personId);
            $reader = new ChangeLogSectionReader();
            $history = $this->userHistory($reader, $personId);
            $this->assertSame(['create', 'update'], array_map(static fn($row): string => $row->entry->mutation, $history));

            JournalReceiptScope::run(JournalReceiptData::web(1453, null, 1453, 'person.zone.same', 'test-agent'),
                function () use ($personId): void {
                    Hilos::$db->users[$personId]->actions->setLastBrowserTimeZone(self::FIRST_BROWSER_ZONE);
                });
            $this->assertCount(2, $this->userHistory($reader, $personId), 'An equal write must leave no journal record');

            JournalReceiptScope::run(JournalReceiptData::web(1453, null, 1453, 'person.zone.second', 'test-agent'),
                function () use ($personId): void {
                    Hilos::$db->users[$personId]->actions->setLastBrowserTimeZone(self::SECOND_BROWSER_ZONE);
                });
            $history = $this->userHistory($reader, $personId);
            $this->assertSame(['create', 'update', 'update'], array_map(static fn($row): string => $row->entry->mutation, $history));
            $this->assertSame([], $history[0]->entry->changes);
            foreach ([1, 2] as $index) {
                $entry = Hilos::$db->changeLogEntries->sectionEntry($history[$index]->entry->id);
                $this->assertNotNull($entry);
                $this->assertCount(1, $entry->changes);
                $change = $entry->changes[0];
                $this->assertSame('last_browser_time_zone', $change->field);
                $this->assertSame('fact', $change->kind);
                $this->assertTrue($change->oldPresent);
                $this->assertTrue($change->newPresent);
                $this->assertNull($change->oldValue);
                $this->assertNull($change->newValue);
            }
            $this->assertSame($firstReceiptId, $history[1]->entry->receiptId);
            $this->assertSame('person.zone.second', $history[2]->receipt?->action);

            // No receipt of the three, and nothing the journal stored since the test began, names a zone.
            $zones = [self::FIRST_BROWSER_ZONE, self::SECOND_BROWSER_ZONE];
            foreach ($history as $row) {
                $this->assertNotNull($row->receipt);
                $attribution = json_encode([
                    $row->receipt->channel, $row->receipt->action, $row->receipt->agent, $row->receipt->source,
                ], JSON_THROW_ON_ERROR);
                foreach ($zones as $zone) {
                    $this->assertStringNotContainsString($zone, $attribution);
                }
            }
            $this->assertSame(0, $this->journalRowsNaming($scratch, $zones));
            $this->assertSame(0, $this->receiptsOfAction($scratch, 'person.zone.same'));
        } finally {
            $this->closeJournalScratch($scratch, static function () use (&$personId): void {
                if ($personId !== null && isset(Hilos::$db->users[$personId])) {
                    Hilos::$db->users[$personId]->actions->delete();
                }
            });
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

    /**
     * Points the journal at this project's trigger files and remembers what the schema and the journal held.
     *
     * @return array{triggers: list<array<string, mixed>>, baseline: array<string, int>} Triggers present
     *     and the largest id of each journal table before the test wrote anything
     */
    private function openJournalScratch(): array
    {
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        Database::sql('SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
        $triggers = Database::rows();
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $baseline = [];
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS id FROM {$journal}.`{$table}`");
            $baseline[$table] = (int)Database::field('id');
        }

        return ['triggers' => $triggers, 'baseline' => $baseline];
    }

    /**
     * Gives the schema and the journal back as openJournalScratch() found them.
     *
     * @param array{triggers: list<array<string, mixed>>, baseline: array<string, int>} $scratch What openJournalScratch() returned
     * @param callable $removeRows Removes the rows the test wrote, called while no trigger is installed
     */
    private function closeJournalScratch(array $scratch, callable $removeRows): void
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        Database::sql('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
        foreach (Database::rows() as $trigger) {
            Database::sql('DROP TRIGGER `' . $trigger['TRIGGER_NAME'] . '`');
        }
        $removeRows();
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sqlRun("DELETE FROM {$journal}.`{$table}` WHERE `id` > ?", [$scratch['baseline'][$table]]);
        }
        foreach ($scratch['triggers'] as $trigger) {
            Database::sql('CREATE TRIGGER `' . $trigger['TRIGGER_NAME'] . '` ' . $trigger['ACTION_TIMING']
                . ' ' . $trigger['EVENT_MANIPULATION'] . ' ON `' . $trigger['EVENT_OBJECT_TABLE']
                . '` FOR EACH ROW ' . $trigger['ACTION_STATEMENT']);
        }
    }

    /**
     * @param ChangeLogSectionReader $reader Journal reader
     * @param int $personId Primary key of the person
     * @return list<ChangeLogHistoryRow> Journal rows of the person, oldest first
     */
    private function userHistory(ChangeLogSectionReader $reader, int $personId): array
    {
        return $reader->historyWindow('hilos_user', new ChangeLogHistoryFilter(recordKey: [$personId]),
            false, null, TableAnchorDirection::After, 10);
    }

    /**
     * Counts what the journal stored since openJournalScratch() in the places a value could land: changes and receipts.
     *
     * @param array{triggers: list<array<string, mixed>>, baseline: array<string, int>} $scratch What openJournalScratch() returned
     * @param list<string> $needles Strings that must not be there
     * @return int Rows holding any of them
     */
    private function journalRowsNaming(array $scratch, array $needles): int
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $found = 0;
        foreach ($needles as $needle) {
            $like = '%' . $needle . '%';
            Database::sql(
                "SELECT COUNT(*) AS found FROM {$journal}.`hilos_change_log_change` WHERE `id` > ? "
                . 'AND (`old_value` LIKE ? OR `new_value` LIKE ?)',
                [$scratch['baseline']['hilos_change_log_change'], $like, $like],
            );
            $found += (int)Database::field('found');
            Database::sql(
                "SELECT COUNT(*) AS found FROM {$journal}.`hilos_change_log_receipt` WHERE `id` > ? "
                . 'AND CONCAT_WS(\'|\', `channel`, `action`, `agent`, `source`) LIKE ?',
                [$scratch['baseline']['hilos_change_log_receipt'], $like],
            );
            $found += (int)Database::field('found');
        }

        return $found;
    }

    /**
     * @param array{triggers: list<array<string, mixed>>, baseline: array<string, int>} $scratch What openJournalScratch() returned
     * @param string $action Action name the receipt was opened with
     * @return int Receipts of that action the journal still holds since openJournalScratch()
     */
    private function receiptsOfAction(array $scratch, string $action): int
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql(
            "SELECT COUNT(*) AS found FROM {$journal}.`hilos_change_log_receipt` WHERE `id` > ? AND `action` = ?",
            [$scratch['baseline']['hilos_change_log_receipt'], $action],
        );

        return (int)Database::field('found');
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
