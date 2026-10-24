<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use DateTimeImmutable;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\ChangeLog\Section\ChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItem;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItemKind;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\ChangeLog\Section\ChangeLogReceipt;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\UnboundedCollectionReadException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\SqlRuntime\TableNotFoundException;
use Hilos\Database\Migration;
use Hilos\Database\Object\Collection\ChangeLogEntries as ObjectChangeLogEntries;
use Hilos\Database\Object\Collection\ChangeLogReceipts as ObjectChangeLogReceipts;
use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use ReflectionProperty;

/** Schema and trigger DDL belong outside the managed rollback root; case rows never escape it. */
abstract class ChangeLogCollectionsEnvironment extends RollbackIntegrationTestCase
{
    private ?HilosDbContext $previousDb = null;
    private ?int $executionToken = null;
    private bool $previousDeliveryMode = false;
    /** @var array<string, array<string, mixed>> Original trigger definitions */
    private array $originalTriggers = [];
    private bool $triggersCaptured = false;

    /** Mounts real chat collections and grants the primary writer only, with explicit journal reader readiness. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousDb = Hilos::$db;
        $this->previousDeliveryMode = new ReflectionProperty(SourceInterestRegistry::class, 'readsWhatIsDelivered')->getValue();
        Database::initialize(initHilos: Hilos::$db === null);
        Hilos::$db = new ChatDbContext();
        Hilos::$db->configure();
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->originalTriggers = self::triggers();
        $this->triggersCaptured = true;
        ChangeLogPartitions::ensure();
        JournalTriggerInstaller::apply();
        SourceInterestRegistry::readsWhatIsDelivered();
        foreach ([
            HilosDbContext::users, HilosDbContext::changeLogTables, HilosDbContext::changeLogFields,
            HilosDbContext::changeLogReceipts, HilosDbContext::changeLogEntries,
            HilosDbContext::changeLogChanges, HilosDbContext::changeLogValues,
        ] as $key) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $key, 'change-log-collections-test');
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
        }
        TruthSourceRegistry::register(HilosDbContext::users, TruthSourceKeys::all(), 'change-log-collections-test');
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: 'change-log-collections-test'));
    }

    /** Restores triggers, facade, reader mode and ownership after managed rollback and ORM reset. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            Database::useConnection(0);
            if ($this->triggersCaptured) {
                foreach (self::triggers() as $row) {
                    Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
                }
                foreach ($this->originalTriggers as $row) {
                    Database::sql('CREATE TRIGGER `' . $row['TRIGGER_NAME'] . '` ' . $row['ACTION_TIMING']
                        . ' ' . $row['EVENT_MANIPULATION'] . ' ON `' . $row['EVENT_OBJECT_TABLE']
                        . '` FOR EACH ROW ' . $row['ACTION_STATEMENT']);
                }
                self::assertSame($this->originalTriggers, self::triggers());
            }
        } finally {
            TruthSourceRegistry::unregisterAgent('change-log-collections-test');
            SourceInterestRegistry::releaseConsumer('change-log-collections-test');
            $this->previousDeliveryMode ? SourceInterestRegistry::readsWhatIsDelivered() : SourceInterestRegistry::readsWhatItMounts();
            if ($this->executionToken !== null) {
                ExecutionContext::pop($this->executionToken);
            }
            Hilos::$db = $this->previousDb;
        }
    }

    /** @return array<string, array<string, mixed>> Original primary trigger catalog */
    private static function triggers(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return array_column(Database::rows(), null, 'TRIGGER_NAME');
    }
}

/** Actual facade reads share the trigger writer's connection, without applying journal DB-sync frames. */
#[IntegrationTest]
#[BreaksOrmState]
final class ChangeLogCollectionsIntegrationTest extends ChangeLogCollectionsEnvironment
{
    /** A fresh bounded read sees trigger inserts after an earlier empty window and a missing key. */
    public function testTriggerWritesAppearWithoutJournalSync(): void
    {
        $query = new TableQueryDTO(limit: 3, sort: TableSortOrderDTO::of(new TableSortDTO('id', TableConstants::ORDER_DESC)));
        $before = Hilos::$db->changeLogEntries->queryPageItems($query);
        $count = count(Hilos::$db->changeLogEntries);
        Database::sql('SELECT AUTO_INCREMENT AS `next_id` FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [ChangeLogDatabase::configuredName(), 'hilos_change_log']);
        $nextId = (int)Database::field('next_id');
        $this->assertArrayNotHasKey($nextId, Hilos::$db->changeLogEntries);
        $this->assertNull(Hilos::$db->changeLogEntries[$nextId] ?? null);

        $person = Hilos::$db->users->actions->createWithName('Facade trigger fixture');
        $this->assertArrayHasKey($nextId, Hilos::$db->changeLogEntries);
        $entry = Hilos::$db->changeLogEntries[$nextId];
        $this->assertSame('[' . $person->id . ']', $entry->record_key);
        $this->assertNull($entry->receipt_id);
        $this->assertSame($count + 1, count(Hilos::$db->changeLogEntries));
        $after = Hilos::$db->changeLogEntries->queryPageItems($query);
        $this->assertSame($nextId, $after[TableConstants::RESULT_KEY_ROWS][0]->id);
        $this->assertNotContains($nextId, array_map(static fn ($row): int => $row->id, $before[TableConstants::RESULT_KEY_ROWS]));
        $this->assertSame($before[TableConstants::RESULT_KEY_TOTAL_COUNT] + 1, $after[TableConstants::RESULT_KEY_TOTAL_COUNT]);
        $this->assertSame($count, $before[TableConstants::RESULT_KEY_TOTAL_COUNT]);

        $table = Hilos::$db->changeLogTables[$entry->table_id];
        $this->assertSame('hilos_user', $table->name);
        $this->assertSame((string)$entry->id, $entry->getIdString());
        // The trigger emits no journal frame; all these reads used the mounted collection directly.
        $this->assertSame([], new ReflectionProperty(Objects::class, 'objects')->getValue(
            Hilos::$db->changeLogEntries->getObjectCollection(),
        ));
    }

    /** A repeated key/page returns new snapshots, while an already handed-out item keeps its fields. */
    public function testReceiptMissUpdateDeleteAndRepeatedPageAreFresh(): void
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql('SELECT AUTO_INCREMENT AS `next_id` FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [ChangeLogDatabase::configuredName(), 'hilos_change_log_receipt']);
        $missingReceiptId = (int)Database::field('next_id');
        $this->assertArrayNotHasKey($missingReceiptId, Hilos::$db->changeLogReceipts);
        $receiptId = JournalReceiptScope::run(
            JournalReceiptData::web(12, 13, 14, 'facade.receipt', 'fixture-agent'),
            static function (): ?int {
                Hilos::$db->users->actions->createWithName('Receipt trigger fixture');
                return JournalReceiptScope::currentId();
            },
        );
        $this->assertSame($missingReceiptId, $receiptId);
        $this->assertArrayHasKey($receiptId, Hilos::$db->changeLogReceipts);
        $query = new TableQueryDTO(limit: 10, search: 'facade.receipt', searchableFields: ['action' => 'action']);
        $first = Hilos::$db->changeLogReceipts->queryPageItems($query)[TableConstants::RESULT_KEY_ROWS][0];
        $keySnapshot = Hilos::$db->changeLogReceipts[$receiptId];
        Database::sql("UPDATE {$journal}.`hilos_change_log_receipt` SET `agent` = ?, `open_handovers` = 7 WHERE `id` = ?",
            ['changed-agent', $receiptId]);
        $second = Hilos::$db->changeLogReceipts->queryPageItems($query)[TableConstants::RESULT_KEY_ROWS][0];
        $this->assertSame($receiptId, $second->id);
        $this->assertSame('changed-agent', $second->agent);
        $this->assertSame(7, Hilos::$db->changeLogReceipts[$receiptId]->open_handovers);
        $this->assertNotSame($first, $second);
        $this->assertSame('fixture-agent', $first->agent);
        $this->assertSame('fixture-agent', $keySnapshot->agent);
        $this->assertSame(0, $first->open_handovers);

        Database::sql("DELETE FROM {$journal}.`hilos_change_log_receipt` WHERE `id` = ?", [$receiptId]);
        $this->assertArrayNotHasKey($receiptId, Hilos::$db->changeLogReceipts);
        $this->assertNull(Hilos::$db->changeLogReceipts[$receiptId] ?? null);
        $this->assertSame([], Hilos::$db->changeLogReceipts->queryPageItems($query)[TableConstants::RESULT_KEY_ROWS]);
        $this->expectException(ItemNotFoundException::class);
        Hilos::$db->changeLogReceipts[$receiptId];
    }

    /** All six mounted chains preserve exact bytes, nulls, booleans and DATETIME(6), without row/page retention or N+1. */
    public function testRoundTripAndManyWindowsDoNotCacheOrReloadEachRow(): void
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $at = '2026-10-20 12:34:56.123456';
        $hash = "\0\xff" . str_repeat("\x80a", 15);
        Database::sql("INSERT INTO {$journal}.`hilos_change_log_table` (`name`) VALUES ('facade_fixture')");
        $tableId = Database::lastInsertId();
        Database::sql("INSERT INTO {$journal}.`hilos_change_log_field` (`table_id`, `name`) VALUES (?, 'fixture_field')", [$tableId]);
        $fieldId = Database::lastInsertId();
        Database::sql("INSERT INTO {$journal}.`hilos_change_log_receipt` (`created_at`, `channel`, `action`) VALUES (?, 'cli', 'roundtrip')", [$at]);
        $receiptId = Database::lastInsertId();
        Database::sql("INSERT INTO {$journal}.`hilos_change_log`"
            . ' (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)'
            . " VALUES (?, ?, ?, '[123]', ?, 'update')", [$at, $receiptId, $tableId, $hash]);
        $entryId = Database::lastInsertId();
        Database::sql("INSERT INTO {$journal}.`hilos_change_log_change`"
            . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)'
            . " VALUES (?, ?, ?, 'long', 0, 1, NULL, 'inline bytes')", [$at, $entryId, $fieldId]);
        $changeId = Database::lastInsertId();
        $longValue = str_repeat('Long UTF-8 value Я ', 1000);
        Database::sql("INSERT INTO {$journal}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)"
            . ' VALUES (?, ?, NULL, ?)', [$at, $changeId, $longValue]);
        $valueId = Database::lastInsertId();
        for ($i = 0; $i < 30; $i++) {
            Database::sql("INSERT INTO {$journal}.`hilos_change_log_receipt` (`created_at`, `channel`, `action`)"
                . " VALUES (?, 'cli', 'roundtrip')", [$at]);
        }
        // Ambient connection 1 must not redirect any of the facade's reads away from its Entity binding.
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            $this->assertSame('facade_fixture', Hilos::$db->changeLogTables[$tableId]->name);
            $this->assertSame($tableId, Hilos::$db->changeLogFields[$fieldId]->table_id);
            $this->assertSame('fixture_field', Hilos::$db->changeLogFields[$fieldId]->name);
            $this->assertSame($at, Hilos::$db->changeLogReceipts[$receiptId]->created_at);
            $this->assertNull(Hilos::$db->changeLogReceipts[$receiptId]->actor_user_id);
            $this->assertNull(Hilos::$db->changeLogReceipts[$receiptId]->subject_user_id);
            $this->assertNull(Hilos::$db->changeLogReceipts[$receiptId]->session_id);
            $this->assertNull(Hilos::$db->changeLogReceipts[$receiptId]->agent);
            $this->assertNull(Hilos::$db->changeLogReceipts[$receiptId]->source);
            $this->assertSame($hash, Hilos::$db->changeLogEntries[$entryId]->record_key_hash);
            $this->assertSame($at, Hilos::$db->changeLogEntries[$entryId]->created_at);
            $entrySnapshot = Hilos::$db->changeLogEntries[$entryId];
            Database::sql("UPDATE {$journal}.`hilos_change_log` SET `record_key_hash` = NULL WHERE `id` = ?", [$entryId], index: 0);
            $this->assertNull(Hilos::$db->changeLogEntries[$entryId]->record_key_hash);
            $this->assertSame($hash, $entrySnapshot->record_key_hash);
            $this->assertSame($at, Hilos::$db->changeLogChanges[$changeId]->created_at);
            $this->assertFalse(Hilos::$db->changeLogChanges[$changeId]->old_present);
            $this->assertTrue(Hilos::$db->changeLogChanges[$changeId]->new_present);
            $this->assertNull(Hilos::$db->changeLogChanges[$changeId]->old_value);
            $this->assertSame('inline bytes', Hilos::$db->changeLogChanges[$changeId]->new_value);
            $this->assertSame($at, Hilos::$db->changeLogValues[$valueId]->created_at);
            $this->assertSame($longValue, Hilos::$db->changeLogValues[$valueId]->new_value);
            $this->assertNull(Hilos::$db->changeLogValues[$valueId]->old_value);
            foreach ([
                HilosDbContext::changeLogTables => $tableId, HilosDbContext::changeLogFields => $fieldId,
                HilosDbContext::changeLogReceipts => $receiptId, HilosDbContext::changeLogEntries => $entryId,
                HilosDbContext::changeLogChanges => $changeId, HilosDbContext::changeLogValues => $valueId,
            ] as $key => $id) {
                $collection = Hilos::$db->{$key};
                $this->assertSame((string)$id, $collection[$id]->getIdString());
                $beforeLookup = self::selectCount();
                $collection[$id];
                // Each statement has a SQL-mode SELECT from the rollback guard; the trailing counter has one too.
                $this->assertSame(3, self::selectCount() - $beforeLookup, $key . ' one key lookup plus guard probes');
                $beforeSmall = self::selectCount();
                $collection->queryPageItems(new TableQueryDTO(limit: 1));
                $smallQueries = self::selectCount() - $beforeSmall;
                $beforeLarge = self::selectCount();
                $page = $collection->queryPageItems(new TableQueryDTO(limit: 30));
                $this->assertSame($smallQueries, self::selectCount() - $beforeLarge, $key . ' page has no N+1');
                $this->assertSame(5, $smallQueries, $key . ' count plus bounded SELECT and guard probes');
                $this->assertNotEmpty($page[TableConstants::RESULT_KEY_ROWS]);
                for ($window = 0; $window < 40; $window++) {
                    $windowQuery = new TableQueryDTO(limit: 3, pageIndex: $window % 10);
                    $snapshot = $collection->queryPageItems($windowQuery)[TableConstants::RESULT_KEY_ROWS];
                    if ($key === HilosDbContext::changeLogReceipts && $snapshot !== []) {
                        Database::sql("UPDATE {$journal}.`hilos_change_log_receipt` SET `open_handovers` = ? WHERE `id` = ?",
                            [$window + 1, $snapshot[0]->id], index: 0);
                        $fresh = $collection->queryPageItems($windowQuery)[TableConstants::RESULT_KEY_ROWS][0];
                        $this->assertSame($snapshot[0]->id, $fresh->id);
                        $this->assertSame($window + 1, $fresh->open_handovers);
                        $this->assertNotSame($snapshot[0], $fresh);
                    }
                    $this->assertSame([], new ReflectionProperty(DbCollection::class, 'items')->getValue($collection), $key);
                    $this->assertSame([], new ReflectionProperty(Objects::class, 'objects')->getValue($collection->getObjectCollection()), $key);
                    $this->assertFalse(new ReflectionProperty(Objects::class, '_allLoaded')->getValue($collection->getObjectCollection()), $key);
                }
            }
        } finally {
            Database::useConnection(0);
        }
        $this->expectException(UnboundedCollectionReadException::class);
        Hilos::$db->changeLogEntries->queryPageItems(new TableQueryDTO());
    }

    /** Scalar projections follow SQL rename/delete without changing warmed items or earlier snapshots. */
    public function testUserNamesAreFreshWithoutChangingTheItemCacheOrAmbientConnection(): void
    {
        $renamed = Hilos::$db->users->actions->createWithName('Projection original name');
        $deleted = Hilos::$db->users->actions->createWithName('Projection deleted name');
        $renamed = Hilos::$db->users[$renamed->id];
        $deleted = Hilos::$db->users[$deleted->id];
        $binding = Hilos::$db->users->getObjectCollection()->getDatabase();
        $table = $binding->getSqlTableName(Hilos::$db->users->getObjectCollection()->getTableName());
        $cachedObjects = new ReflectionProperty(Objects::class, 'objects')->getValue(Hilos::$db->users->getObjectCollection());

        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Database::sql("SELECT -77 AS `id`, 'Wrong connection' AS `name`");
            $beforeEmpty = self::selectCount();
            $this->assertSame([], Hilos::$db->users->namesByIds([]));
            // Only the trailing counter's rollback-guard probe is a SELECT.
            $this->assertSame(1, self::selectCount() - $beforeEmpty);
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());

            $before = Hilos::$db->users->namesByIds([$renamed->id, $deleted->id, PHP_INT_MAX, $renamed->id]);
            $this->assertEquals([
                $renamed->id => 'Projection original name',
                $deleted->id => 'Projection deleted name',
            ], $before);
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());

            Database::sql("UPDATE {$table} SET `name` = ? WHERE `id` = ?",
                ["Projection renamed !%_ O'Neil", $renamed->id], index: $binding->connectionIndex);
            Database::sql("DELETE FROM {$table} WHERE `id` = ?", [$deleted->id], index: $binding->connectionIndex);
            $this->assertSame([
                $renamed->id => "Projection renamed !%_ O'Neil",
            ], Hilos::$db->users->namesByIds([$renamed->id, $deleted->id, PHP_INT_MAX]));
            $this->assertSame([], Hilos::$db->users->namesByIds([$deleted->id, PHP_INT_MAX]));
            $this->assertSame([$renamed->id], Hilos::$db->users->idsMatchingName("renamed !%_ O'Neil"));
            $this->assertSame([], Hilos::$db->users->idsMatchingName('Projection original name'));
            $this->assertSame([], Hilos::$db->users->idsMatchingName('Projection deleted name'));
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());

            $this->assertEquals([
                $renamed->id => 'Projection original name',
                $deleted->id => 'Projection deleted name',
            ], $before);
            $this->assertSame($renamed, Hilos::$db->users[$renamed->id]);
            $this->assertSame($deleted, Hilos::$db->users[$deleted->id]);
            $this->assertSame('Projection original name', Hilos::$db->users[$renamed->id]->name);
            $this->assertSame('Projection deleted name', Hilos::$db->users[$deleted->id]->name);
            $this->assertSame($cachedObjects,
                new ReflectionProperty(Objects::class, 'objects')->getValue(Hilos::$db->users->getObjectCollection()));
        } finally {
            Database::useConnection(0);
        }
    }

    /** LIKE operators remain literal while case and accent matching retain the name column's SQL collation. */
    public function testUserNameSearchPreservesLiteralCharactersAndSqlCollation(): void
    {
        $bang = Hilos::$db->users->actions->createWithName('Projection ! mark')->id;
        $percent = Hilos::$db->users->actions->createWithName('Projection % mark')->id;
        $underscore = Hilos::$db->users->actions->createWithName('Projection _ mark')->id;
        $combined = Hilos::$db->users->actions->createWithName('Projection !%_ mark')->id;
        $accent = Hilos::$db->users->actions->createWithName('Projection Élodie')->id;
        $backslash = Hilos::$db->users->actions->createWithName('Projection \\ mark')->id;
        Hilos::$db->users->actions->createWithName('Projection X mark');

        $this->assertEqualsCanonicalizing([$bang, $combined], Hilos::$db->users->idsMatchingName('!'));
        $this->assertEqualsCanonicalizing([$percent, $combined], Hilos::$db->users->idsMatchingName('%'));
        $this->assertEqualsCanonicalizing([$underscore, $combined], Hilos::$db->users->idsMatchingName('_'));
        $this->assertSame([$combined], Hilos::$db->users->idsMatchingName('!%_'));
        $this->assertSame([$accent], Hilos::$db->users->idsMatchingName('projection eLODIE'));
        $this->assertSame([$backslash], Hilos::$db->users->idsMatchingName('\\'));
        $this->assertSame([], Hilos::$db->users->idsMatchingName("' OR 1=1 --"));
        $this->assertSame([], Hilos::$db->users->idsMatchingName('Projection missing'));
    }

    /** Search includes every match beyond the table count ceiling and never hydrates the matching users. */
    public function testUserNameSearchDoesNotTruncateOrHydrateMatches(): void
    {
        $binding = Hilos::$db->users->getObjectCollection()->getDatabase();
        $table = $binding->getSqlTableName(Hilos::$db->users->getObjectCollection()->getTableName());
        $this->assertSame([], Hilos::$db->users->idsMatchingName('Unbounded projection person '));
        $ids = [];
        for ($i = 0; $i <= TableConstants::COUNT_CEILING; $i++) {
            Database::sql("INSERT INTO {$table} (`name`) VALUES (?)", ['Unbounded projection person ' . $i],
                index: $binding->connectionIndex);
            $ids[] = Database::lastInsertId($binding->connectionIndex);
        }
        $this->assertCount(501, $ids);
        $this->assertSame([], new ReflectionProperty(Objects::class, 'objects')->getValue(Hilos::$db->users->getObjectCollection()));
        $beforeSearch = self::selectCount();
        $this->assertEqualsCanonicalizing($ids, Hilos::$db->users->idsMatchingName('Unbounded projection person '));
        $this->assertSame(3, self::selectCount() - $beforeSearch, 'One id projection, with rollback-guard probes');
        $beforeNames = self::selectCount();
        $this->assertEquals([
            $ids[0] => 'Unbounded projection person 0',
            $ids[250] => 'Unbounded projection person 250',
            $ids[500] => 'Unbounded projection person 500',
        ], Hilos::$db->users->namesByIds([$ids[0], $ids[250], $ids[500]]));
        $this->assertSame(3, self::selectCount() - $beforeNames, 'One batch projection, with rollback-guard probes');
        $this->assertSame([], new ReflectionProperty(Objects::class, 'objects')->getValue(Hilos::$db->users->getObjectCollection()));
    }

    /** Real SQL refusals from both inherited reads leave the foreign ambient connection selected. */
    public function testUserProjectionSqlFailuresPreserveTheAmbientConnection(): void
    {
        $originalUsers = Hilos::$db->users->getObjectCollection();
        Hilos::$db->users->setObjectCollection(MissingUserProjectionObjects::initDB(Objects::LAZY_STRATEGY_KEY));
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            foreach ([
                static fn (): array => Hilos::$db->users->namesByIds([1]),
                static fn (): array => Hilos::$db->users->idsMatchingName('anything'),
            ] as $read) {
                try {
                    $read();
                    $this->fail('The projection must propagate its SQL failure');
                } catch (TableNotFoundException $failure) {
                    $this->assertStringContainsString('hilos_missing_users_projection', $failure->getMessage());
                }
                $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
            }
        } finally {
            Hilos::$db->users->setObjectCollection($originalUsers);
            Database::useConnection(0);
        }
    }

    /** Section snapshots observe trigger inserts without sync and never acquire later membership or values. */
    public function testSectionEntriesSeeNewTriggerRowsWithoutChangingEarlierSnapshots(): void
    {
        JournalReceiptScope::run(
            JournalReceiptData::web(12, 13, 14, 'section.fresh', 'fixture-agent'),
            function (): void {
                $receiptId = JournalReceiptScope::currentId();
                $this->assertNotNull($receiptId);
                $empty = Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 50);
                $this->assertTrue($empty->isEmpty());
                $nextId = (int)Database::sql(
                    'SELECT AUTO_INCREMENT AS `next_id` FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [ChangeLogDatabase::configuredName(), 'hilos_change_log'], index: 0,
                )->field('next_id');
                $missing = Hilos::$db->changeLogEntries->sectionEntriesByIds([$nextId], true);
                $this->assertTrue($missing->isEmpty());
                $this->assertNull(Hilos::$db->changeLogEntries->sectionEntry($nextId));
                $this->assertTrue(Hilos::$db->changeLogEntries->sectionEntriesByIds([], false)->isEmpty());

                $personId = Hilos::$db->users->actions->createWithName('Section fresh person')->id;
                Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
                try {
                    Database::sql("SELECT -77 AS `id`, 'Wrong connection result' AS `name`");
                    $entry = Hilos::$db->changeLogEntries->sectionEntry($nextId);
                    $this->assertNotNull($entry);
                    $this->assertSame($nextId, $entry->id);
                    $this->assertSame($receiptId, $entry->receiptId);
                    $this->assertSame('hilos_user', $entry->table);
                    $this->assertSame([$personId], $entry->recordKey);
                    $this->assertSame('create', $entry->mutation);
                    $this->assertSame([], $entry->changes);
                    $this->assertSame('UTC', $entry->createdAt->getTimezone()->getName());
                    $row = Database::sql('SELECT `created_at` FROM '
                        . ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName()) . '.`hilos_change_log` WHERE `id` = ?',
                        [$nextId], index: 0)->row();
                    $this->assertSame($row['created_at'], $entry->createdAt->format('Y-m-d H:i:s.u'));
                    $first = Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 50);
                    $this->assertSame([0], array_keys($first->toArray()));
                    $this->assertEquals($entry, $first->first());
                    $map = Hilos::$db->changeLogEntries->sectionEntriesByIds([$nextId, PHP_INT_MAX, $nextId], true);
                    $this->assertSame([$nextId], array_keys($map->toArray()));
                    $this->assertEquals($entry, $map->get($nextId));
                    $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
                } finally {
                    Database::useConnection(0);
                }

                Hilos::$db->users->actions->createWithName('Section later person');
                $this->assertCount(2, Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 50));
                $this->assertCount(1, $first);
                $this->assertTrue($empty->isEmpty());
                $this->assertTrue($missing->isEmpty());
                $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
                Database::sql("UPDATE {$journal}.`hilos_change_log` SET `record_key` = '[redacted]' WHERE `id` = ?", [$nextId], index: 0);
                $this->assertNull(Hilos::$db->changeLogEntries->sectionEntry($nextId)->recordKey);
                $this->assertSame([$personId], $entry->recordKey);
                $this->assertSame([$personId], $first->first()->recordKey);
                $this->assertSame([], new ReflectionProperty(Objects::class, 'objects')->getValue(
                    Hilos::$db->changeLogEntries->getObjectCollection(),
                ));
            },
        );
    }

    /** Receipt portions retain complete time/id ordering, enforce cursor ownership, and cap only the window. */
    public function testSectionReceiptPortionsAndFiniteIdBatches(): void
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $receiptId = self::receiptWithPeople(55);
        $ids = array_map(static fn(array $row): int => (int)$row['id'], Database::sql(
            "SELECT `id` FROM {$journal}.`hilos_change_log` WHERE `receipt_id` = ? ORDER BY `id`", [$receiptId], index: 0,
        )->rows());
        $this->assertCount(55, $ids);
        Database::sql("UPDATE {$journal}.`hilos_change_log` SET `created_at` = '2026-10-20 12:34:56.123456' WHERE `receipt_id` = ?",
            [$receiptId], index: 0);
        Database::sql("UPDATE {$journal}.`hilos_change_log` SET `created_at` = '2026-10-20 12:34:55.999999' WHERE `id` = ?",
            [$ids[54]], index: 0);
        $expectedOrder = [$ids[54], ...array_slice($ids, 0, 54)];

        $first = Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 999);
        $this->assertCount(50, $first);
        $this->assertSame(range(0, 49), array_keys($first->toArray()));
        $this->assertSame(array_slice($expectedOrder, 0, 50), array_column($first->toArray(), 'id'));
        $this->assertSame('2026-10-20 12:34:55.999999', $first->first()->createdAt->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-10-20 12:34:56.123456', $first->last()->createdAt->format('Y-m-d H:i:s.u'));
        $next = Hilos::$db->changeLogEntries->receiptEntries($receiptId, $first->last()->id, 50);
        $this->assertCount(5, $next);
        $this->assertSame(range(0, 4), array_keys($next->toArray()));
        $this->assertSame(array_slice($expectedOrder, 50), array_column($next->toArray(), 'id'));
        $this->assertTrue(Hilos::$db->changeLogEntries->receiptEntries($receiptId, $next->last()->id, 50)->isEmpty());
        $this->assertSame([$expectedOrder[1]], array_column(
            Hilos::$db->changeLogEntries->receiptEntries($receiptId, $first->first()->id, 1)->toArray(), 'id',
        ));
        foreach ([0, -1] as $limit) {
            $this->assertTrue(Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, $limit)->isEmpty());
        }
        $this->assertTrue(Hilos::$db->changeLogEntries->receiptEntries($receiptId, PHP_INT_MAX, 50)->isEmpty());
        $this->assertTrue(Hilos::$db->changeLogEntries->receiptEntries(PHP_INT_MAX, null, 50)->isEmpty());

        $singleReceipt = self::receiptWithPeople(1);
        $single = Hilos::$db->changeLogEntries->receiptEntries($singleReceipt, null, 50);
        $this->assertCount(1, $single);
        $this->assertTrue(Hilos::$db->changeLogEntries->receiptEntries($singleReceipt, $single->last()->id, 50)->isEmpty());
        $this->assertTrue(Hilos::$db->changeLogEntries->receiptEntries($receiptId, $single->first()->id, 50)->isEmpty());
        $this->assertTrue(Hilos::$db->changeLogEntries->receiptEntries($singleReceipt, $first->first()->id, 50)->isEmpty());
        $exactReceipt = self::receiptWithPeople(50);
        $exact = Hilos::$db->changeLogEntries->receiptEntries($exactReceipt, null, 50);
        $this->assertCount(50, $exact);
        $this->assertTrue(Hilos::$db->changeLogEntries->receiptEntries($exactReceipt, $exact->last()->id, 50)->isEmpty());

        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            $before = self::selectCount();
            $map = Hilos::$db->changeLogEntries->sectionEntriesByIds([...array_reverse($ids), $ids[0], PHP_INT_MAX], false);
            $this->assertSame(9, self::selectCount() - $before, 'Two bounded batches, two reads each, with guard probes');
            $this->assertCount(55, $map);
            $this->assertEqualsCanonicalizing($ids, array_keys($map->toArray()));
            $this->assertNull($map->get(PHP_INT_MAX));
            foreach ($map as $id => $entry) {
                $this->assertSame($id, $entry->id);
                $this->assertSame($receiptId, $entry->receiptId);
                $this->assertSame('create', $entry->mutation);
                $this->assertSame([], $entry->changes);
            }
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        } finally {
            Database::useConnection(0);
        }
    }

    /** Full detail distinguishes long/inline/fact and absent/NULL/empty sides, without truncating fields. */
    public function testSectionEntryPreservesAllFieldValuesAndCompositeJoinKeys(): void
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $receiptId = self::receiptWithPeople(1);
        $source = Database::sql("SELECT `id`, `created_at`, `table_id` FROM {$journal}.`hilos_change_log` WHERE `receipt_id` = ?",
            [$receiptId], index: 0)->row();
        $entryId = (int)$source['id'];
        $at = $source['created_at'];
        $tableId = (int)$source['table_id'];
        $oldLong = str_repeat('Before Я ', 1000);
        $newLong = str_repeat('After Ж ', 1000);
        $expected = [
            ['section_null_empty', 'inline', true, true, null, '', false],
            ['section_absent_null', 'inline', false, true, null, null, false],
            ['section_deleted_empty', 'inline', true, false, '', null, false],
            ['section_fact', 'fact', true, true, null, null, false],
            ['section_long', 'long', true, true, $oldLong, $newLong, false],
            ['section_long_null_empty', 'long', true, true, null, '', false],
            ['section_long_absent', 'long', false, true, null, null, false],
        ];
        for ($i = 0; $i < 60; $i++) {
            $expected[] = ['section_extra_' . $i, 'inline', true, true, 'old ' . $i, 'new ' . $i, false];
        }
        foreach ($expected as [$name, $kind, $oldPresent, $newPresent, $oldValue, $newValue]) {
            Database::sql("INSERT INTO {$journal}.`hilos_change_log_field` (`table_id`, `name`) VALUES (?, ?)", [$tableId, $name], index: 0);
            $fieldId = Database::lastInsertId(0);
            Database::sql("INSERT INTO {$journal}.`hilos_change_log_change`"
                . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$at, $entryId, $fieldId, $kind, (int)$oldPresent, (int)$newPresent,
                    $kind === 'long' ? 'inline decoy old' : $oldValue, $kind === 'long' ? 'inline decoy new' : $newValue], index: 0);
            $changeId = Database::lastInsertId(0);
            if ($kind === 'long') {
                Database::sql("INSERT INTO {$journal}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)"
                    . ' VALUES (?, ?, ?, ?)', [$at, $changeId, $oldValue, $newValue], index: 0);
                Database::sql("INSERT INTO {$journal}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)"
                    . " VALUES ('2026-10-19 01:00:00.000001', ?, 'wrong old time', 'wrong new time')", [$changeId], index: 0);
            }
        }
        Database::sql("INSERT INTO {$journal}.`hilos_change_log_change`"
            . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)'
            . " VALUES ('2026-10-19 01:00:00.000001', ?, ?, 'inline', 1, 1, 'wrong old entry time', 'wrong new entry time')",
            [$entryId, $fieldId], index: 0);

        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            $detail = Hilos::$db->changeLogEntries->sectionEntry($entryId);
            $this->assertNotNull($detail);
            $this->assertSame($receiptId, $detail->receiptId);
            $this->assertSame('hilos_user', $detail->table);
            $this->assertSame($at, $detail->createdAt->format('Y-m-d H:i:s.u'));
            $this->assertCount(67, $detail->changes);
            $summary = Hilos::$db->changeLogReceipts->sectionReceipt($receiptId);
            $this->assertSame(1, $summary->entryCount);
            $this->assertSame(67, $summary->touched[0]->single->changedFields);
            foreach ($detail->changes as $index => $change) {
                $this->assertSame($expected[$index], array_values(get_object_vars($change)));
            }
            $this->assertEquals($detail, Hilos::$db->changeLogEntries->sectionEntriesByIds([$entryId], true)->get($entryId));
            $this->assertEquals($detail, Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 1)->first());
            $brief = Hilos::$db->changeLogEntries->sectionEntriesByIds([$entryId], false)->get($entryId);
            $this->assertCount(67, $brief->changes);
            foreach ($brief->changes as $index => $change) {
                $wanted = $expected[$index];
                if ($wanted[1] === 'long') {
                    $wanted[4] = null;
                    $wanted[5] = null;
                    $wanted[6] = true;
                }
                $this->assertSame($wanted, array_values(get_object_vars($change)));
            }
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        } finally {
            Database::useConnection(0);
        }
    }

    /** Real create/update/delete triggers retain the saved long body after the business row has gone. */
    public function testSectionEntriesReadRealLongValueTriggers(): void
    {
        $old = str_repeat('Original prompt Я ', 1000);
        $new = str_repeat('Changed prompt Ж ', 1000);
        $promptId = null;
        $receiptId = JournalReceiptScope::run(
            JournalReceiptData::web(12, 13, 14, 'section.real.long', 'fixture-agent'),
            static function () use ($old, $new, &$promptId): ?int {
                Database::sql('INSERT INTO `moderator_prompt_piece` (`section`, `prompt_piece`) VALUES (?, ?)',
                    ['message_rule', $old], index: 0);
                $promptId = Database::lastInsertId(0);
                Database::sql('UPDATE `moderator_prompt_piece` SET `prompt_piece` = ? WHERE `id` = ?', [$new, $promptId], index: 0);
                Database::sql('DELETE FROM `moderator_prompt_piece` WHERE `id` = ?', [$promptId], index: 0);
                return JournalReceiptScope::currentId();
            },
        );
        $this->assertNotNull($receiptId);
        $entries = Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 50);
        $this->assertCount(3, $entries);
        $this->assertSame(['create', 'update', 'delete'], array_column($entries->toArray(), 'mutation'));
        foreach ($entries as $entry) {
            $this->assertSame('moderator_prompt_piece', $entry->table);
            $this->assertSame([$promptId], $entry->recordKey);
            $this->assertSame($receiptId, $entry->receiptId);
            $this->assertEquals($entry, Hilos::$db->changeLogEntries->sectionEntry($entry->id));
        }
        $this->assertSame([], $entries->first()->changes);
        $update = $entries->get(1);
        $this->assertCount(1, $update->changes);
        $this->assertSame(['prompt_piece', 'long', true, true, $old, $new, false], array_values(get_object_vars($update->changes[0])));
        $omitted = Hilos::$db->changeLogEntries->sectionEntriesByIds([$update->id], false)->get($update->id);
        $this->assertSame(['prompt_piece', 'long', true, true, null, null, true], array_values(get_object_vars($omitted->changes[0])));
        $deleted = array_column($entries->last()->changes, null, 'field');
        $this->assertSame(['section', 'prompt_piece'], array_keys($deleted));
        $this->assertSame(['prompt_piece', 'long', true, false, $new, null, false], array_values(get_object_vars($deleted['prompt_piece'])));
        $this->assertSame(['section', 'inline', true, false, 'message_rule', null, false],
            array_values(get_object_vars($deleted['section'])));
        $this->assertSame(0, (int)Database::sql('SELECT COUNT(*) AS `n` FROM `moderator_prompt_piece` WHERE `id` = ?',
            [$promptId], index: 0)->field('n'));
    }

    /** Stored keys retain large integer strings and legacy scalar conversion; malformed or masked keys stay null. */
    public function testSectionEntryRecordKeysAndBareRows(): void
    {
        $personId = Hilos::$db->users->actions->createWithName('Bare section person')->id;
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $entryId = (int)Database::sql("SELECT `id` FROM {$journal}.`hilos_change_log` WHERE `record_key` = ? AND `receipt_id` IS NULL",
            ['[' . $personId . ']'], index: 0)->field('id');
        $original = Hilos::$db->changeLogEntries->sectionEntry($entryId);
        $this->assertNotNull($original);
        $this->assertNull($original->receiptId);
        $this->assertSame([$personId], $original->recordKey);
        foreach ([
            ['[9223372036854775808,"0007",null,true,false,1.25]', ['9223372036854775808', '0007', null, 1, 0, '1.25']],
            ['[]', []], ['[redacted]', null], ['{broken', null], ['{"id": 1}', null],
            ['[[1]]', null], ['[{}]', null], ['null', null],
        ] as [$stored, $decoded]) {
            Database::sql("UPDATE {$journal}.`hilos_change_log` SET `record_key` = ? WHERE `id` = ?", [$stored, $entryId], index: 0);
            $this->assertSame($decoded, Hilos::$db->changeLogEntries->sectionEntry($entryId)->recordKey, $stored);
        }
        $this->assertSame([$personId], $original->recordKey);
        Database::sql("DELETE FROM {$journal}.`hilos_change_log` WHERE `id` = ?", [$entryId], index: 0);
        $this->assertNull(Hilos::$db->changeLogEntries->sectionEntry($entryId));
        $this->assertTrue(Hilos::$db->changeLogEntries->sectionEntriesByIds([$entryId], true)->isEmpty());
        $this->assertSame([$personId], $original->recordKey);
    }

    /** All three facade section APIs keep the caller's connection selected when real journal SQL fails. */
    public function testSectionEntrySqlFailuresPreserveTheAmbientConnection(): void
    {
        $originalEntries = Hilos::$db->changeLogEntries->getObjectCollection();
        Hilos::$db->changeLogEntries->setObjectCollection(MissingSectionEntryObjects::initDB(Objects::LAZY_STRATEGY_KEY));
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            foreach ([
                static fn (): ?ChangeLogEntry => Hilos::$db->changeLogEntries->sectionEntry(1),
                static fn (): ChangeLogReadCollection => Hilos::$db->changeLogEntries->sectionEntriesByIds([1], true),
                static fn (): ChangeLogReadCollection => Hilos::$db->changeLogEntries->receiptEntries(1, null, 50),
                static fn (): ChangeLogReadCollection => Hilos::$db->changeLogEntries->receiptEntries(1, 1, 50),
            ] as $read) {
                try {
                    $read();
                    $this->fail('Section read must propagate its SQL failure');
                } catch (TableNotFoundException $failure) {
                    $this->assertStringContainsString('hilos_missing_section_entry', $failure->getMessage());
                }
                $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
            }
        } finally {
            Hilos::$db->changeLogEntries->setObjectCollection($originalEntries);
            Database::useConnection(0);
        }
    }

    /** An empty receipt becomes visible after a real trigger insert; previous Section snapshots stay unchanged. */
    public function testSectionFeedAndReceiptSnapshotsSeeTriggerInsertsWithoutSync(): void
    {
        $filter = new ChangeLogFeedFilter(who: '#940001');
        $empty = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50);
        $this->assertTrue($empty->isEmpty());
        $this->assertSame(0, Hilos::$db->changeLogReceipts->feedCount($filter));
        $this->assertNull(Hilos::$db->changeLogReceipts->sectionReceipt(PHP_INT_MAX));
        $this->assertTrue(Hilos::$db->changeLogReceipts->sectionReceiptsByIds([])->isEmpty());
        $this->assertTrue(Hilos::$db->changeLogReceipts->sectionHeadersByIds([])->isEmpty());
        JournalReceiptScope::run(
            JournalReceiptData::web(940001, null, 35, 'receipts.fresh', 'receipt-writer'),
            function () use ($filter, $empty): void {
                $id = JournalReceiptScope::currentId();
                $this->assertNotNull($id);
                $this->assertNull(Hilos::$db->changeLogReceipts->sectionReceipt($id));
                $emptySummary = Hilos::$db->changeLogReceipts->sectionReceiptsByIds([$id])->get($id);
                $this->assertSame(0, $emptySummary->entryCount);
                $this->assertSame([], $emptySummary->touched);
                $this->assertSame(0, Hilos::$db->changeLogReceipts->feedCount($filter));
                $person = Hilos::$db->users->actions->createWithName('Receipt fresh person');
                Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
                try {
                    Database::sql("SELECT -77 AS `id`, 'Wrong ambient result' AS `name`");
                    $window = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50);
                    $this->assertSame(['receipt:' . $id], self::feedKeys($window));
                    $this->assertSame([0], array_keys($window->toArray()));
                    $receipt = Hilos::$db->changeLogReceipts->sectionReceipt($id);
                    $this->assertEquals($receipt, $window->first()->receipt);
                    $this->assertSame(ChangeLogFeedItemKind::RECEIPT, $window->first()->kind);
                    $this->assertNull($window->first()->entry);
                    $this->assertEquals($receipt->createdAt, $window->first()->createdAt);
                    $this->assertSame('UTC', $window->first()->createdAt->getTimezone()->getName());
                    $this->assertSame('Deleted user #940001', $receipt->actor->label);
                    $this->assertSame(940001, $receipt->actor->userId);
                    $this->assertTrue($receipt->actor->deleted);
                    $this->assertNull($receipt->subject);
                    $this->assertSame(35, $receipt->sessionId);
                    $this->assertSame('web', $receipt->channel);
                    $this->assertSame('receipts.fresh', $receipt->action);
                    $this->assertSame('receipt-writer', $receipt->agent);
                    $this->assertSame('session #35', $receipt->source);
                    $this->assertSame(1, $receipt->entryCount);
                    $this->assertSame('hilos_user', $receipt->touched[0]->table);
                    $this->assertSame(1, $receipt->touched[0]->records);
                    $this->assertSame([$person->id], $receipt->touched[0]->single->recordKey);
                    $this->assertSame('create', $receipt->touched[0]->single->mutation);
                    $this->assertSame(0, $receipt->touched[0]->single->changedFields);
                    $headers = Hilos::$db->changeLogReceipts->sectionHeadersByIds([$id, PHP_INT_MAX, $id]);
                    $this->assertSame([$id], array_keys($headers->toArray()));
                    $this->assertEquals(get_object_vars($headers->get($id)), array_intersect_key(
                        get_object_vars($receipt), get_object_vars($headers->get($id)),
                    ));
                    $anchor = new ChangeLogFeedAnchor($window->first()->createdAt, ChangeLogFeedItemKind::RECEIPT, $id);
                    $this->assertSame(1, Hilos::$db->changeLogReceipts->feedCount($filter));
                    $this->assertSame(0, Hilos::$db->changeLogReceipts->feedCountBefore($filter, $anchor));
                    $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
                } finally {
                    Database::useConnection(0);
                }
                Hilos::$db->users->actions->createWithName('Receipt second person');
                $this->assertSame(2, Hilos::$db->changeLogReceipts->sectionReceipt($id)->entryCount);
                $this->assertSame(1, $receipt->entryCount);
                $this->assertSame(1, $window->first()->receipt->entryCount);
                $this->assertSame(0, $emptySummary->entryCount);
                $this->assertTrue($empty->isEmpty());
                $this->assertSame([], new ReflectionProperty(Objects::class, 'objects')->getValue(
                    Hilos::$db->changeLogReceipts->getObjectCollection(),
                ));
            },
        );
    }

    /** The table emits dense row lists and fresh counts while earlier windows remain frozen. */
    public function testFeedTableReadsFreshFacadeWindowsAndKeepsPaginationMetadata(): void
    {
        $table = new HilosChangeLogFeedTable();
        $filter = [HilosChangeLogFeedTable::FILTER_TABLE => 'hilos_user', HilosChangeLogFeedTable::FILTER_CHANNEL => 'web'];
        $empty = $table->getPage(new TableQueryDTO(search: '#950001', filter: $filter));
        $this->assertSame([], $empty->rows);
        $this->assertSame(0, $empty->totalCount);
        $this->assertTrue($empty->totalExact);
        $this->assertSame(50, $empty->limit);
        $this->assertSame(0, $empty->rowsBefore);
        $this->assertNull($empty->firstAnchor);
        $this->assertNull($empty->lastAnchor);

        $firstId = self::attributedReceipt(JournalReceiptData::web(950001, null, 35, 'table.fresh', 'table-writer'));
        $single = $table->getPage(new TableQueryDTO(search: '#950001', limit: 2, filter: $filter));
        $this->assertSame(['receipt:' . $firstId], array_map(static fn($row): string => $row->rowKey, $single->rows));
        $this->assertSame([0], array_keys($single->rows));
        $this->assertSame(1, $single->totalCount);
        $this->assertSame(0, $single->rowsBefore);
        $this->assertEquals($single->firstAnchor, $single->lastAnchor);
        $this->assertSame($firstId, $single->firstAnchor->values['id']);
        $this->assertSame('receipt', $single->firstAnchor->values['kind']);
        $this->assertMatchesRegularExpression('/\.\d{6}$/D', $single->firstAnchor->values['createdAt']);
        $this->assertSame('Deleted user #950001', $single->rows[0]->actorLabel);
        $this->assertSame('hilos_user', $single->rows[0]->touched[0]['table']);
        $this->assertSame(1, $single->rows[0]->touched[0]['records']);

        $secondId = self::attributedReceipt(JournalReceiptData::web(950001, null, 35, 'table.second', 'table-writer'));
        $thirdId = self::attributedReceipt(JournalReceiptData::web(950001, null, 35, 'table.third', 'table-writer'));
        $first = $table->getPage(new TableQueryDTO(search: '#950001', limit: 2, filter: $filter));
        $this->assertSame(['receipt:' . $thirdId, 'receipt:' . $secondId], array_map(static fn($row): string => $row->rowKey, $first->rows));
        $this->assertSame([0, 1], array_keys($first->rows));
        $this->assertSame(3, $first->totalCount);
        $this->assertTrue($first->totalExact);
        $this->assertSame(2, $first->limit);
        $this->assertSame(0, $first->rowsBefore);
        $next = $table->getPage(new TableQueryDTO(search: '#950001', limit: 2, filter: $filter, anchor: $first->lastAnchor));
        $this->assertSame(['receipt:' . $firstId], array_map(static fn($row): string => $row->rowKey, $next->rows));
        $this->assertSame(2, $next->rowsBefore);
        $this->assertEquals($single->firstAnchor, $next->firstAnchor);
        $previous = $table->getPage(new TableQueryDTO(
            search: '#950001', limit: 2, filter: $filter, anchor: $next->firstAnchor, anchorDirection: TableAnchorDirection::Before,
        ));
        $this->assertEquals($first, $previous);
        $numbered = $table->getPage(new TableQueryDTO(search: '#950001', limit: 2, filter: $filter, pageIndex: 1));
        $this->assertEquals($next, $numbered);
        $outside = $table->getPage(new TableQueryDTO(search: '#950001', limit: 2, filter: $filter, pageIndex: 2));
        $this->assertSame([], $outside->rows);
        $this->assertSame(3, $outside->totalCount);
        $this->assertSame(4, $outside->rowsBefore);
        $this->assertSame([], $empty->rows);
        $this->assertSame(0, $empty->totalCount);
        $this->assertSame(1, $single->totalCount);
        $this->assertSame(['receipt:' . $firstId], array_map(static fn($row): string => $row->rowKey, $single->rows));
    }

    /** Equal microsecond timestamps and equal ids across kinds remain distinct in both directions and counts. */
    public function testSectionFeedFullAnchorsEdgesAndSkip(): void
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $receipt = self::receiptWithPeople(1);
        $otherReceipt = self::receiptWithPeople(1);
        $bareIds = [];
        for ($i = 0; $i < 3; $i++) {
            $person = Hilos::$db->users->actions->createWithName('Full anchor bare ' . $i);
            $bareIds[] = (int)Database::sql("SELECT `id` FROM {$journal}.`hilos_change_log` WHERE `receipt_id` IS NULL AND `record_key` = ?",
                ['[' . $person->id . ']'], index: 0)->field('id');
        }
        $sharedId = max($receipt, $otherReceipt, ...$bareIds) + 1000;
        foreach ([[$receipt, $sharedId], [$otherReceipt, $sharedId + 1]] as [$old, $new]) {
            Database::sql("UPDATE {$journal}.`hilos_change_log_receipt` SET `id` = ?, `created_at` = '2030-01-01 12:34:56.123456'"
                . ' WHERE `id` = ?', [$new, $old], index: 0);
            Database::sql("UPDATE {$journal}.`hilos_change_log` SET `receipt_id` = ? WHERE `receipt_id` = ?", [$new, $old], index: 0);
        }
        Database::sql("UPDATE {$journal}.`hilos_change_log` SET `id` = ? WHERE `id` = ?", [$sharedId, $bareIds[0]], index: 0);
        Database::sql("UPDATE {$journal}.`hilos_change_log` SET `created_at` = '2030-01-01 12:34:56.123456' WHERE `id` IN (?, ?, ?)",
            [$sharedId, $bareIds[1], $bareIds[2]], index: 0);
        Database::sql("UPDATE {$journal}.`hilos_change_log` SET `created_at` = '2030-01-01 12:34:56.123457' WHERE `id` = ?",
            [$bareIds[2]], index: 0);
        $filter = new ChangeLogFeedFilter(
            since: new DateTimeImmutable('2030-01-01T14:34:56.123456+02:00'),
            until: new DateTimeImmutable('2030-01-01T12:34:57Z'),
        );
        $expected = ['entry:' . $bareIds[2], 'entry:' . $sharedId, 'entry:' . $bareIds[1],
            'receipt:' . ($sharedId + 1), 'receipt:' . $sharedId];
        $all = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50);
        $this->assertSame($expected, self::feedKeys($all));
        $this->assertSame('2030-01-01 12:34:56.123457', $all->first()->createdAt->format('Y-m-d H:i:s.u'));
        $this->assertSame(array_slice($expected, -2), self::feedKeys(
            Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::Before, 2),
        ));
        $this->assertSame(array_slice($expected, 2, 2), self::feedKeys(
            Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 2, 2),
        ));
        $this->assertSame(array_slice($expected, 1, 2), self::feedKeys(
            Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::Before, 2, 2),
        ));
        $this->assertSame($expected, self::feedKeys(
            Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50, -10),
        ));
        foreach ($all as $position => $row) {
            $anchor = new ChangeLogFeedAnchor($row->createdAt, $row->kind, $row->receipt?->id ?? $row->entry->id);
            $this->assertSame(array_slice($expected, $position + 1), self::feedKeys(
                Hilos::$db->changeLogReceipts->feedWindow($filter, $anchor, TableAnchorDirection::After, 50),
            ));
            $this->assertSame(array_slice($expected, 0, $position), self::feedKeys(
                Hilos::$db->changeLogReceipts->feedWindow($filter, $anchor, TableAnchorDirection::Before, 50),
            ));
            $this->assertSame($position, Hilos::$db->changeLogReceipts->feedCountBefore($filter, $anchor));
            $this->assertSame(min($position, 2), Hilos::$db->changeLogReceipts->feedCountBefore($filter, $anchor, 1));
        }
        $this->assertSame(5, Hilos::$db->changeLogReceipts->feedCount($filter));
        foreach ([0, -1] as $limit) {
            $this->assertTrue(Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, $limit)->isEmpty());
        }
        $this->assertTrue(Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50, 501)->isEmpty());
        $last = $all->last();
        $this->expectException(InvalidArgumentException::class);
        Hilos::$db->changeLogReceipts->feedWindow($filter,
            new ChangeLogFeedAnchor($last->createdAt, $last->kind, $sharedId), TableAnchorDirection::After, 1, -1);
    }

    /** Table filtering retains full summaries; names refresh after a raw rename without changing warmed user items. */
    public function testSectionReceiptSummariesAndLightweightHeadersKeepTheirFields(): void
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $actor = Hilos::$db->users->actions->createWithName('Receipt original actor');
        $actor = Hilos::$db->users[$actor->id];
        Database::sql("INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES ('receipt.summary.key', 'string', 'before')", index: 0);
        $settingId = Database::lastInsertId(0);
        $id = JournalReceiptScope::run(JournalReceiptData::web($actor->id, 940002, 42, 'receipt.summary', 'summary-writer'),
            static function (): int {
                Database::sql("UPDATE `hilos_setting` SET `value` = 'after' WHERE `key` = 'receipt.summary.key'", index: 0);
                Hilos::$db->users->actions->createWithName('Summary first person');
                Hilos::$db->users->actions->createWithName('Summary second person');
                return JournalReceiptScope::currentId();
            });
        $before = Hilos::$db->changeLogReceipts->sectionReceipt($id);
        $this->assertSame(3, $before->entryCount);
        $this->assertSame(['hilos_setting', 'hilos_user'], array_column($before->touched, 'table'));
        $this->assertSame([1, 2], array_column($before->touched, 'records'));
        $this->assertSame([$settingId], $before->touched[0]->single->recordKey);
        $this->assertSame('update', $before->touched[0]->single->mutation);
        $this->assertSame(1, $before->touched[0]->single->changedFields);
        $this->assertNull($before->touched[1]->single);
        $filter = new ChangeLogFeedFilter(who: '#' . $actor->id, table: 'hilos_user', channel: 'web');
        $window = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50);
        $this->assertSame(['receipt:' . $id], self::feedKeys($window));
        $this->assertEquals($before, $window->first()->receipt);
        $this->assertSame(1, Hilos::$db->changeLogReceipts->feedCount($filter));
        $this->assertSame(0, Hilos::$db->changeLogReceipts->feedCount(new ChangeLogFeedFilter(who: '#' . $actor->id, table: 'absent')));
        Database::sql("UPDATE `hilos_user` SET `name` = 'Receipt fresh actor' WHERE `id` = ?", [$actor->id], index: 0);
        $fresh = Hilos::$db->changeLogReceipts->sectionReceipt($id);
        $this->assertSame('Receipt fresh actor', $fresh->actor->label);
        $this->assertFalse($fresh->actor->deleted);
        $this->assertSame('Receipt original actor', $before->actor->label);
        $this->assertSame('Receipt original actor', Hilos::$db->users[$actor->id]->name);
        $this->assertSame(940002, $fresh->subject->userId);
        $this->assertSame('Deleted user #940002', $fresh->subject->label);
        $this->assertTrue($fresh->subject->deleted);
        $settingEntry = (int)Database::sql("SELECT l.`id` FROM {$journal}.`hilos_change_log` l"
            . " JOIN {$journal}.`hilos_change_log_table` t ON t.`id` = l.`table_id`"
            . " WHERE l.`receipt_id` = ? AND t.`name` = 'hilos_setting'", [$id], index: 0)->field('id');
        foreach ([['[9223372036854775808,"0007",null]', ['9223372036854775808', '0007', null]],
            ['[redacted]', null], ['{broken', null]] as [$stored, $decoded]) {
            Database::sql("UPDATE {$journal}.`hilos_change_log` SET `record_key` = ? WHERE `id` = ?", [$stored, $settingEntry], index: 0);
            $this->assertSame($decoded, Hilos::$db->changeLogReceipts->sectionReceipt($id)->touched[0]->single->recordKey);
        }
        $beforeHeader = self::selectCount();
        $headers = Hilos::$db->changeLogReceipts->sectionHeadersByIds([$id]);
        $this->assertSame(5, self::selectCount() - $beforeHeader, 'One header query and one person projection, with guard probes');
        $this->assertSame('Receipt fresh actor', $headers->get($id)->actor->label);
        $this->assertSame('receipt.summary', $headers->get($id)->action);
        $this->assertFalse(property_exists($headers->get($id), 'entryCount'));
        $this->assertFalse(property_exists($headers->get($id), 'touched'));
    }

    /** Numeric, deleted and literal name filters match actor OR subject and exclude bare entries. */
    public function testSectionFeedPersonFiltersPreserveSoftIdsAndLiteralSqlSearch(): void
    {
        $actor = Hilos::$db->users->actions->createWithName('Receipt Élodie !%_ actor')->id;
        $subject = Hilos::$db->users->actions->createWithName('Receipt subject')->id;
        $actorReceipt = self::attributedReceipt(JournalReceiptData::web($actor, null, null, 'who.actor', null));
        $subjectReceipt = self::attributedReceipt(JournalReceiptData::web(null, $actor, null, 'who.subject', null));
        self::attributedReceipt(JournalReceiptData::web($subject, null, null, 'who.other', null));
        $expected = ['receipt:' . $subjectReceipt, 'receipt:' . $actorReceipt];
        foreach ([(string)$actor, '#' . $actor, '000' . $actor, '#000' . $actor,
            'receipt eLODIE', '!', '%', '_', '!%_'] as $who) {
            $filter = new ChangeLogFeedFilter(who: $who);
            $this->assertSame($expected, self::feedKeys(
                Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50),
            ), $who);
            $this->assertSame(2, Hilos::$db->changeLogReceipts->feedCount($filter), $who);
        }
        foreach (['9223372036854775808', '#999999999999999999999999', 'Receipt absent person', "' OR 1=1 --"] as $who) {
            $filter = new ChangeLogFeedFilter(who: $who);
            $this->assertSame(0, Hilos::$db->changeLogReceipts->feedCount($filter));
            $this->assertTrue(Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50)->isEmpty());
        }
        Database::sql('DELETE FROM `hilos_user` WHERE `id` = ?', [$actor], index: 0);
        $this->assertSame($expected, self::feedKeys(Hilos::$db->changeLogReceipts->feedWindow(
            new ChangeLogFeedFilter(who: '#000' . $actor), null, TableAnchorDirection::After, 50,
        )));
        $this->assertSame('Deleted user #' . $actor, Hilos::$db->changeLogReceipts->sectionReceipt($actorReceipt)->actor->label);
        $this->assertSame(0, Hilos::$db->changeLogReceipts->feedCount(new ChangeLogFeedFilter(who: '!%_')));
        $ids = [];
        for ($i = 0; $i <= TableConstants::COUNT_CEILING; $i++) {
            Database::sql('INSERT INTO `hilos_user` (`name`) VALUES (?)', ['Receipt many matches ' . $i], index: 0);
            $ids[] = Database::lastInsertId(0);
        }
        $lastMatch = self::attributedReceipt(JournalReceiptData::web(null, $ids[500], null, 'who.last-match', null));
        $filter = new ChangeLogFeedFilter(who: 'Receipt many matches');
        $this->assertSame(['receipt:' . $lastMatch], self::feedKeys(
            Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50),
        ));
        $this->assertSame(1, Hilos::$db->changeLogReceipts->feedCount($filter));
    }

    /** A count probes only ceiling+1 rows, while windows and batch maps remain finite and dense/keyed as promised. */
    public function testSectionFeedBoundedCountsWindowsAndReceiptBatches(): void
    {
        $ids = [];
        for ($i = 0; $i < 505; $i++) {
            $ids[] = self::attributedReceipt(JournalReceiptData::cli('receipt.bounded', null));
        }
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        Database::sql("UPDATE {$journal}.`hilos_change_log_receipt` SET `created_at` = '2030-02-01 00:00:00.000001'"
            . " WHERE `id` IN ({$placeholders})", $ids, index: 0);
        $filter = new ChangeLogFeedFilter(channel: 'cli', table: 'hilos_user',
            since: new DateTimeImmutable('2030-02-01T00:00:00.000001Z'), until: new DateTimeImmutable('2030-02-01T00:00:00.000002Z'));
        foreach ([[500, 501], [999, 501], [2, 3], [0, 1], [-5, 1]] as [$ceiling, $expected]) {
            $beforeCount = self::selectCount();
            $this->assertSame($expected, Hilos::$db->changeLogReceipts->feedCount($filter, $ceiling));
            $this->assertSame(3, self::selectCount() - $beforeCount, 'Count never reads headers or hydrates entries');
        }
        $first = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 999);
        $this->assertCount(50, $first);
        $this->assertSame(range(0, 49), array_keys($first->toArray()));
        $this->assertSame(array_map(static fn(int $id): string => 'receipt:' . $id, array_slice(array_reverse($ids), 0, 50)),
            self::feedKeys($first));
        $last = $first->last();
        $anchor = new ChangeLogFeedAnchor($last->createdAt, $last->kind, $last->receipt->id);
        $next = Hilos::$db->changeLogReceipts->feedWindow($filter, $anchor, TableAnchorDirection::After, 50);
        $this->assertCount(50, $next);
        $this->assertSame($ids[454], $next->first()->receipt->id);
        $tail = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50, 500);
        $this->assertCount(5, $tail);
        $this->assertSame($ids[0], $tail->last()->receipt->id);
        $oldestAnchor = new ChangeLogFeedAnchor($tail->last()->createdAt, $tail->last()->kind, $ids[0]);
        $this->assertSame(501, Hilos::$db->changeLogReceipts->feedCountBefore($filter, $oldestAnchor));
        $this->assertSame(3, Hilos::$db->changeLogReceipts->feedCountBefore($filter, $oldestAnchor, 2));
        $this->assertSame(0, Hilos::$db->changeLogReceipts->feedCount(new ChangeLogFeedFilter(channel: 'web', since: $filter->since)));
        $this->assertSame(0, Hilos::$db->changeLogReceipts->feedCount(new ChangeLogFeedFilter(since: $filter->until)));
        $selected = [...array_slice($ids, 0, 55), $ids[0], PHP_INT_MAX];
        $beforeHeaders = self::selectCount();
        $headers = Hilos::$db->changeLogReceipts->sectionHeadersByIds($selected);
        $this->assertSame(5, self::selectCount() - $beforeHeaders, 'Two bounded header queries without entry queries');
        $beforeSummaries = self::selectCount();
        $summaries = Hilos::$db->changeLogReceipts->sectionReceiptsByIds($selected);
        $this->assertSame(13, self::selectCount() - $beforeSummaries, 'Two batches of header, aggregate and single-record queries');
        $this->assertCount(55, $headers);
        $this->assertCount(55, $summaries);
        $this->assertEqualsCanonicalizing(array_slice($ids, 0, 55), array_keys($summaries->toArray()));
        $this->assertNull($summaries->get(PHP_INT_MAX));
        foreach ($summaries as $id => $summary) {
            $this->assertSame($id, $summary->id);
            $this->assertSame(1, $summary->entryCount);
            $this->assertNull($summary->actor);
            $this->assertNull($summary->subject);
            $this->assertNull($summary->sessionId);
            $this->assertNull($summary->agent);
            $this->assertSame('receipt.bounded', $summary->action);
            $this->assertSame('receipt.bounded', $summary->source);
            $this->assertSame('cli', $summary->channel);
            $this->assertEquals($headers->get($id)->createdAt, $summary->createdAt);
            $this->assertSame('create', $summary->touched[0]->single->mutation);
        }
        $bigReceipt = self::receiptWithPeople(55);
        $this->assertSame(55, Hilos::$db->changeLogReceipts->sectionReceipt($bigReceipt)->entryCount);
        $this->assertSame(55, Hilos::$db->changeLogReceipts->sectionReceipt($bigReceipt)->touched[0]->records);
        $this->assertNull(Hilos::$db->changeLogReceipts->sectionReceipt($bigReceipt)->touched[0]->single);
    }

    /** Bare-entry feed reads omit long bodies and preserve saved values after deletion of the business row. */
    public function testSectionBareFeedRetainsEntryShapeWithoutReadingLongBodies(): void
    {
        $old = str_repeat('Bare original Я ', 1000);
        $new = str_repeat('Bare changed Ж ', 1000);
        Database::sql('INSERT INTO `moderator_prompt_piece` (`section`, `prompt_piece`) VALUES (?, ?)',
            ['message_rule', $old], index: 0);
        $promptId = Database::lastInsertId(0);
        Database::sql('UPDATE `moderator_prompt_piece` SET `prompt_piece` = ? WHERE `id` = ?', [$new, $promptId], index: 0);
        Database::sql('DELETE FROM `moderator_prompt_piece` WHERE `id` = ?', [$promptId], index: 0);
        $filter = new ChangeLogFeedFilter(table: 'moderator_prompt_piece');
        $before = self::selectCount();
        $feed = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50);
        $this->assertSame(7, self::selectCount() - $before, 'Feed, entry and field queries only, with guard probes');
        $this->assertCount(3, $feed);
        $this->assertSame(['delete', 'update', 'create'], array_map(
            static fn(ChangeLogFeedItem $row): string => $row->entry->mutation, $feed->toArray(),
        ));
        foreach ($feed as $row) {
            $this->assertSame(ChangeLogFeedItemKind::ENTRY, $row->kind);
            $this->assertNull($row->receipt);
            $this->assertNull($row->entry->receiptId);
            $this->assertSame([$promptId], $row->entry->recordKey);
            $this->assertSame('moderator_prompt_piece', $row->entry->table);
            $this->assertEquals($row->createdAt, $row->entry->createdAt);
        }
        $update = $feed->get(1)->entry;
        $this->assertSame(['prompt_piece', 'long', true, true, null, null, true],
            array_values(get_object_vars($update->changes[0])));
        $this->assertSame(['prompt_piece', 'long', true, true, $old, $new, false], array_values(get_object_vars(
            Hilos::$db->changeLogEntries->sectionEntry($update->id)->changes[0],
        )));
        $deletedFields = array_column($feed->first()->entry->changes, null, 'field');
        $this->assertSame(['section', 'prompt_piece'], array_keys($deletedFields));
        $this->assertSame(['section', 'inline', true, false, 'message_rule', null, false],
            array_values(get_object_vars($deletedFields['section'])));
        $this->assertSame(['prompt_piece', 'long', true, false, null, null, true],
            array_values(get_object_vars($deletedFields['prompt_piece'])));
        $this->assertSame([], $feed->last()->entry->changes);
        $this->assertSame(3, Hilos::$db->changeLogReceipts->feedCount($filter));
        $this->assertSame(0, Hilos::$db->changeLogReceipts->feedCount(
            new ChangeLogFeedFilter(table: 'moderator_prompt_piece', channel: 'web'),
        ));
    }

    /** Every Receipts facade read propagates real SQL failure and leaves the caller's ambient connection unchanged. */
    public function testSectionReceiptSqlFailuresPreserveTheAmbientConnection(): void
    {
        $originalReceipts = Hilos::$db->changeLogReceipts->getObjectCollection();
        Hilos::$db->changeLogReceipts->setObjectCollection(MissingSectionReceiptObjects::initDB(Objects::LAZY_STRATEGY_KEY));
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            $filter = new ChangeLogFeedFilter();
            $anchor = new ChangeLogFeedAnchor(new DateTimeImmutable('2030-01-01T00:00:00Z'), ChangeLogFeedItemKind::RECEIPT, 1);
            foreach ([
                static fn (): ChangeLogReadCollection => Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 1),
                static fn (): int => Hilos::$db->changeLogReceipts->feedCount($filter),
                static fn (): int => Hilos::$db->changeLogReceipts->feedCountBefore($filter, $anchor),
                static fn (): ?ChangeLogReceipt => Hilos::$db->changeLogReceipts->sectionReceipt(1),
                static fn (): ChangeLogReadCollection => Hilos::$db->changeLogReceipts->sectionReceiptsByIds([1]),
                static fn (): ChangeLogReadCollection => Hilos::$db->changeLogReceipts->sectionHeadersByIds([1]),
            ] as $read) {
                try {
                    $read();
                    $this->fail('Receipt read must propagate its SQL failure');
                } catch (TableNotFoundException $failure) {
                    $this->assertStringContainsString('hilos_missing_section_receipt', $failure->getMessage());
                }
                $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
            }
        } finally {
            Hilos::$db->changeLogReceipts->setObjectCollection($originalReceipts);
            Database::useConnection(0);
        }
    }

    /**
     * @param ChangeLogReadCollection<ChangeLogFeedItem> $window Selected feed snapshot
     * @return list<string> Complete source identities in window order
     */
    private static function feedKeys(ChangeLogReadCollection $window): array
    {
        $keys = [];
        foreach ($window as $row) {
            $keys[] = $row->kind->value . ':' . ($row->receipt?->id ?? $row->entry->id);
        }
        return $keys;
    }

    /**
     * @param JournalReceiptData $attribution Fixture attribution
     * @return int Receipt with one real trigger-written user entry
     */
    private static function attributedReceipt(JournalReceiptData $attribution): int
    {
        return JournalReceiptScope::run($attribution, static function (): int {
            Hilos::$db->users->actions->createWithName('Receipt attributed person');
            return JournalReceiptScope::currentId();
        });
    }

    /**
     * @param int $count Number of real trigger-written create entries
     * @return int Nonempty receipt whose users and journal rows share the managed rollback root
     */
    private static function receiptWithPeople(int $count): int
    {
        return JournalReceiptScope::run(
            JournalReceiptData::web(12, 13, 14, 'section.portions', 'fixture-agent'),
            static function () use ($count): int {
                for ($i = 0; $i < $count; $i++) {
                    Hilos::$db->users->actions->createWithName('Section portion person ' . $i);
                }
                return JournalReceiptScope::currentId();
            },
        );
    }

    /** @return int SELECT statements actually executed on the Entity's row connection */
    private static function selectCount(): int
    {
        return (int)Database::sql("SHOW SESSION STATUS LIKE 'Com_select'", index: 0)->field('Value');
    }
}

/** Preserves the real Entity binding while directing inherited projections at an absent table. */
final class MissingUserProjectionObjects extends ObjectUsers
{
    /** @return string Absent table used to provoke a real SQL failure without DDL */
    public function getTableName(): string
    {
        return 'hilos_missing_users_projection';
    }
}

/** Keeps the real journal Entity binding while provoking an inherited section read failure without DDL. */
final class MissingSectionEntryObjects extends ObjectChangeLogEntries
{
    /** @return string Absent journal table used for a real SQL refusal */
    public function getTableName(): string
    {
        return 'hilos_missing_section_entry';
    }
}

/** Uses the real journal Entity binding while provoking SQL failure without fixture DDL. */
final class MissingSectionReceiptObjects extends ObjectChangeLogReceipts
{
    /** @return string Absent journal receipt table used for real SQL refusal */
    public function getTableName(): string
    {
        return 'hilos_missing_section_receipt';
    }
}
