<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\UnboundedCollectionReadException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Migration;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;
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

    /** @return int SELECT statements actually executed on the Entity's row connection */
    private static function selectCount(): int
    {
        return (int)Database::sql("SHOW SESSION STATUS LIKE 'Com_select'", index: 0)->field('Value');
    }
}

