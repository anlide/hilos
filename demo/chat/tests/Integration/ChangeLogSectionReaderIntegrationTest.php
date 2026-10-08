<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Demo\Chat\Database\Database;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogSectionReader;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItemKind;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryFilter;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Schema\JournalColumnMode;

/** The section reader sees real chat triggers and the two configured databases. */
final class ChangeLogSectionReaderIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.section.hil1452.';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Row-number high water marks before each case */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Triggers found before each case */
    private array $originalTriggers = [];

    /** @var list<int> Fixture people removed after triggers are dropped */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->originalTriggers = self::triggerCatalog();
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$database}.`{$table}`");
            $this->baseline[$table] = (int)Database::field('id');
        }
        JournalTriggerInstaller::apply();
    }

    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        foreach (self::triggerCatalog() as $row) {
            Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
        }
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` LIKE ?', [self::KEY_PREFIX . '%']);
        foreach ($this->userIds as $id) {
            Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$id]);
        }
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sqlRun("DELETE FROM {$database}.`{$table}` WHERE `id` > ?", [$this->baseline[$table]]);
        }
        foreach ($this->originalTriggers as $row) {
            Database::sql('CREATE TRIGGER `' . $row['TRIGGER_NAME'] . '` ' . $row['ACTION_TIMING']
                . ' ' . $row['EVENT_MANIPULATION'] . ' ON `' . $row['EVENT_OBJECT_TABLE']
                . '` FOR EACH ROW ' . $row['ACTION_STATEMENT']);
        }
        parent::tearDown();
    }

    public function testOverviewAndTablesDescribeLiveJournalCoverage(): void
    {
        $reader = new ChangeLogSectionReader();
        $before = $reader->overview();
        $this->assertGreaterThan(0, $before->journalBytes);
        $this->assertGreaterThan(0, $before->liveTables);
        $this->assertGreaterThan(0, $before->journaledTables);
        if ($before->journalEntries === 0) {
            $this->assertNull($before->oldestAt);
        }

        JournalReceiptScope::run(
            JournalReceiptData::web(11, null, 31, 'setting.create', 'section-test'),
            static function (): void {
                Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                    [self::KEY_PREFIX . 'overview', 'string', 'first']);
            },
        );
        $after = $reader->overview();
        $this->assertSame($before->journalEntries + 1, $after->journalEntries);
        $this->assertNotNull($after->oldestAt);
        $this->assertSame($before->liveTables, $after->liveTables);

        $tables = [];
        foreach ($reader->tables() as $summary) {
            $tables[$summary->name] = $summary;
        }
        $this->assertSame($after->liveTables, count($tables));
        $this->assertSame('framework', $tables['hilos_setting']->owner->value);
        $this->assertTrue($tables['hilos_setting']->journaled);
        $this->assertGreaterThanOrEqual(1, $tables['hilos_setting']->changesLast24h);
        $this->assertNotNull($tables['hilos_setting']->lastChangeAt);
        $this->assertSame(1, $tables['hilos_setting']->modeCounts[JournalColumnMode::RECORD_KEY->value]);
        $this->assertSame('project', $tables['bot']->owner->value);
        $this->assertFalse($tables['bot']->journaled);
        $this->assertSame([], $tables['bot']->modeCounts);
        $this->assertNull($tables['bot']->changesLast24h);
    }

    public function testTableUsesLiveColumnPlacementAndTriggerHeaders(): void
    {
        $reader = new ChangeLogSectionReader();
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        $detail = $reader->table('hilos_user');
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        $this->assertNotNull($detail);
        $columns = [];
        foreach ($detail->columns as $column) {
            $columns[$column->name] = $column;
        }
        $this->assertSame(JournalColumnMode::RECORD_KEY, $columns['id']->mode);
        $this->assertNull($columns['id']->storage);
        $this->assertSame(JournalColumnMode::PERSONAL, $columns['name']->mode);
        $this->assertSame('fact', $columns['name']->storage);
        $this->assertSame(JournalColumnMode::NOISE, $columns['last_activity']->mode);
        $this->assertNotEmpty($columns['last_activity']->noiseReason);
        $this->assertCount(3, $detail->triggers);
        $this->assertSame(['INSERT', 'UPDATE', 'DELETE'], array_map(
            static fn($trigger): string => $trigger->event, $detail->triggers,
        ));
        foreach ($detail->triggers as $trigger) {
            $this->assertGreaterThan(0, $trigger->validFromMigration);
        }

        $untracked = $reader->table('bot');
        $this->assertNotNull($untracked);
        $this->assertSame([], $untracked->triggers);
        $this->assertNull($untracked->columns[0]->mode);
        $this->assertNull($reader->table('no_such_table'));
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
    }

    public function testFeedWindowsKeepReceiptAndBareEntryDistinct(): void
    {
        $reader = new ChangeLogSectionReader();
        $since = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $receiptId = JournalReceiptScope::run(
            JournalReceiptData::web(11, null, 31, 'setting.create', 'section-test'),
            static function (): ?int {
                Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                    [self::KEY_PREFIX . 'receipt', 'string', 'first']);
                return JournalReceiptScope::currentId();
            },
        );
        $this->assertNotNull($receiptId);
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            [self::KEY_PREFIX . 'bare', 'string', 'second']);

        $filter = new ChangeLogFeedFilter(table: 'hilos_setting', since: $since);
        $feed = $reader->feedWindow($filter, null, TableAnchorDirection::After, 50);
        $this->assertCount(2, $feed);
        $this->assertSame(ChangeLogFeedItemKind::ENTRY, $feed[0]->kind);
        $this->assertSame(ChangeLogFeedItemKind::RECEIPT, $feed[1]->kind);
        $this->assertSame(2, $reader->feedCount($filter));
        $this->assertSame(2, $reader->feedCount($filter, 1));
        $this->assertSame(1, $reader->feedCountBefore($filter,
            new ChangeLogFeedAnchor($feed[1]->createdAt, $feed[1]->kind, $receiptId)));
        $firstAnchor = new ChangeLogFeedAnchor($feed[0]->createdAt, $feed[0]->kind, $feed[0]->entry->id);
        $older = $reader->feedWindow($filter, $firstAnchor, TableAnchorDirection::After, 1);
        $this->assertSame($receiptId, $older[0]->receipt->id);
        $newer = $reader->feedWindow($filter,
            new ChangeLogFeedAnchor($feed[1]->createdAt, $feed[1]->kind, $receiptId),
            TableAnchorDirection::Before, 1);
        $this->assertSame($feed[0]->entry->id, $newer[0]->entry->id);

        $receipt = $reader->receipt($receiptId);
        $this->assertNotNull($receipt);
        $this->assertSame(1, $receipt->entryCount);
        $this->assertSame('hilos_setting', $receipt->touched[0]->table);
        $this->assertSame('create', $receipt->touched[0]->single->mutation);
        $this->assertSame(0, $receipt->touched[0]->single->changedFields);
        $this->assertCount(1, $reader->receiptEntries($receiptId, null, 50));
        $this->assertSame('create', $reader->entry($feed[0]->entry->id)->mutation);

        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("INSERT INTO {$database}.`hilos_change_log_receipt` (`created_at`, `channel`, `action`)"
            . " VALUES (UTC_TIMESTAMP(6), 'web', 'empty')");
        $emptyId = Database::lastInsertId();
        $this->assertNull($reader->receipt($emptyId));
        $this->assertSame(2, $reader->feedCount($filter));
        $this->assertSame([], $reader->feedWindow(new ChangeLogFeedFilter(
            table: 'hilos_setting', until: $since), null, TableAnchorDirection::After, 50));
    }

    public function testWhoAndChannelFiltersUseFreshPeopleAndExcludeBareEntries(): void
    {
        $actorId = $this->createUser('Section Actor 1452');
        $subjectId = $this->createUser('Section Subject 1452');
        $since = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $receiptId = JournalReceiptScope::run(
            JournalReceiptData::web($actorId, $subjectId, 31, 'setting.update', 'section-test'),
            static function (): ?int {
                Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                    [self::KEY_PREFIX . 'person', 'string', 'first']);
                return JournalReceiptScope::currentId();
            },
        );
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            [self::KEY_PREFIX . 'outside', 'string', 'second']);
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$subjectId]);

        $reader = new ChangeLogSectionReader();
        $receipt = $reader->receipt($receiptId);
        $this->assertNotNull($receipt);
        $this->assertSame($actorId, $receipt->actor->userId);
        $this->assertSame('Section Actor 1452', $receipt->actor->label);
        $this->assertCount(1, $reader->feedWindow(new ChangeLogFeedFilter(
            who: '#' . $actorId, table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50));
        $byName = $reader->feedWindow(new ChangeLogFeedFilter(
            who: ' Actor 1452 ', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byName);
        $this->assertSame($receiptId, $byName[0]->receipt->id);
        $this->assertSame('Section Actor 1452', $byName[0]->receipt->actor->label);
        $this->assertSame('Deleted user #' . $subjectId, $byName[0]->receipt->subject->label);
        $this->assertSame([], $reader->feedWindow(new ChangeLogFeedFilter(
            who: 'Actor%1452', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50));
        $this->assertSame([], $reader->feedWindow(new ChangeLogFeedFilter(
            who: 'Actor_1452', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50));
        $byId = $reader->feedWindow(new ChangeLogFeedFilter(
            who: '#' . $subjectId, table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byId);
        $this->assertSame($receiptId, $byId[0]->receipt->id);
        $this->assertCount(1, $reader->feedWindow(new ChangeLogFeedFilter(
            who: '#000' . $actorId, table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50));
        $byChannel = $reader->feedWindow(new ChangeLogFeedFilter(
            channel: 'web', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byChannel);
        $this->assertSame($receiptId, $byChannel[0]->receipt->id);
        $this->assertSame([], $reader->feedWindow(new ChangeLogFeedFilter(
            channel: 'migration', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50));

        $this->expectException(InvalidArgumentException::class);
        new ChangeLogFeedFilter(channel: 'unknown');
    }

    public function testReceiptEntriesKeepPresenceFlagsAndReadLongBodies(): void
    {
        $key = self::KEY_PREFIX . 'fields';
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            [$key, 'string', 'first']);
        $receiptId = JournalReceiptScope::run(
            JournalReceiptData::migration('up', 91, 'test.sql'),
            static function () use ($key): ?int {
                Database::sqlRun('UPDATE `hilos_setting` SET `type` = ?, `value` = ? WHERE `key` = ?',
                    ['boolean', '1', $key]);
                Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` = ?', [$key]);
                return JournalReceiptScope::currentId();
            },
        );
        $this->assertNotNull($receiptId);
        $reader = new ChangeLogSectionReader();
        $entries = $reader->receiptEntries($receiptId, null, 50);
        $this->assertCount(2, $entries);
        $this->assertSame(['update', 'delete'], array_map(static fn($entry): string => $entry->mutation, $entries));
        $this->assertSame($entries[0]->id, $reader->receiptEntries($receiptId, null, 1)[0]->id);
        $this->assertSame($entries[1]->id, $reader->receiptEntries($receiptId, $entries[0]->id, 1)[0]->id);
        $this->assertSame([], $reader->receiptEntries($receiptId, $entries[1]->id, 1));

        $changes = [];
        foreach ($entries[0]->changes as $change) {
            $changes[$change->field] = $change;
        }
        $this->assertSame('inline', $changes['type']->kind);
        $this->assertSame('string', $changes['type']->oldValue);
        $this->assertSame('boolean', $changes['type']->newValue);
        $this->assertTrue($changes['type']->oldPresent);
        $this->assertTrue($changes['type']->newPresent);
        $this->assertSame('fact', $changes['value']->kind);
        $this->assertNull($changes['value']->oldValue);
        $this->assertNull($changes['value']->newValue);
        $this->assertTrue($changes['value']->oldPresent);
        $this->assertTrue($changes['value']->newPresent);

        $deleteFields = array_column($entries[1]->changes, 'field');
        $this->assertContains('key', $deleteFields);
        $this->assertContains('value', $deleteFields);
        $this->assertNull($reader->receipt(999999999));
        $receipt = $reader->receipt($receiptId);
        $this->assertSame(2, $receipt->entryCount);
        $this->assertSame(2, $receipt->touched[0]->records);
        $this->assertNull($receipt->touched[0]->single);
        $this->assertSame('migration', $receipt->channel);
        $this->assertNull($receipt->actor);
        $this->assertNull($receipt->agent);

        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_table` WHERE `name` = 'hilos_setting'");
        $tableId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_field` (`table_id`, `name`)"
            . " VALUES (?, 'synthetic_long')", [$tableId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_field`"
            . " WHERE `table_id` = ? AND `name` = 'synthetic_long'", [$tableId]);
        $fieldId = (int)Database::field('id');
        $createdAt = $entries[0]->createdAt->format('Y-m-d H:i:s.u');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_field` (`table_id`, `name`)"
            . " VALUES (?, 'synthetic_nullable')", [$tableId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_field`"
            . " WHERE `table_id` = ? AND `name` = 'synthetic_nullable'", [$tableId]);
        $nullableFieldId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_change`"
            . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)'
            . " VALUES (?, ?, ?, 'inline', 1, 0, NULL, NULL)", [$createdAt, $entries[0]->id, $nullableFieldId]);
        Database::sql("INSERT INTO {$database}.`hilos_change_log_change`"
            . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`)'
            . " VALUES (?, ?, ?, 'long', 1, 1)", [$createdAt, $entries[0]->id, $fieldId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_change`"
            . ' WHERE `log_id` = ? AND `field_id` = ?', [$entries[0]->id, $fieldId]);
        $changeId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_value`"
            . ' (`created_at`, `change_id`, `old_value`, `new_value`) VALUES (?, ?, ?, ?)',
            [$createdAt, $changeId, str_repeat('old', 100), str_repeat('new', 100)]);
        $full = $reader->entry($entries[0]->id);
        $fullChanges = [];
        foreach ($full->changes as $change) {
            $fullChanges[$change->field] = $change;
        }
        $long = $fullChanges['synthetic_long'];
        $this->assertSame('long', $long->kind);
        $this->assertSame(str_repeat('old', 100), $long->oldValue);
        $this->assertSame(str_repeat('new', 100), $long->newValue);
        $this->assertFalse($long->bodyOmitted);
        $this->assertTrue($fullChanges['synthetic_nullable']->oldPresent);
        $this->assertFalse($fullChanges['synthetic_nullable']->newPresent);
        $this->assertNull($fullChanges['synthetic_nullable']->oldValue);
    }

    public function testEqualTimeFeedRowsPageAcrossBothKindsWithoutLoss(): void
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("INSERT IGNORE INTO {$database}.`hilos_change_log_table` (`name`) VALUES ('hilos_setting')");
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_table` WHERE `name` = 'hilos_setting'");
        $tableId = (int)Database::field('id');
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $moment = $at->format('Y-m-d H:i:s.u');
        for ($number = 1; $number <= 52; $number++) {
            $key = json_encode([$number]);
            Database::sql("INSERT INTO {$database}.`hilos_change_log`"
                . ' (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)'
                . " VALUES (?, NULL, ?, ?, UNHEX(SHA2(?, 256)), 'create')",
                [$moment, $tableId, $key, $key]);
        }
        Database::sql("INSERT INTO {$database}.`hilos_change_log_receipt`"
            . " (`created_at`, `channel`, `action`) VALUES (?, 'migration', 'tie-window-hil1452')", [$moment]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_receipt`"
            . " WHERE `action` = 'tie-window-hil1452' ORDER BY `id` DESC LIMIT 1");
        $receiptId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log`"
            . ' (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)'
            . " VALUES (?, ?, ?, '[0]', UNHEX(SHA2('[0]', 256)), 'create')",
            [$moment, $receiptId, $tableId]);

        $reader = new ChangeLogSectionReader();
        $filter = new ChangeLogFeedFilter(table: 'hilos_setting', since: $at->modify('-1 second'));
        $first = $reader->feedWindow($filter, null, TableAnchorDirection::After, 100);
        $this->assertCount(ChangeLogSectionReader::MAX_WINDOW_ROWS, $first);
        $this->assertSame(53, $reader->feedCount($filter));
        $this->assertSame(3, $reader->feedCount($filter, 2));
        $last = $first[array_key_last($first)];
        $next = $reader->feedWindow($filter,
            new ChangeLogFeedAnchor($last->createdAt, $last->kind, $last->entry->id),
            TableAnchorDirection::After, 50);
        $this->assertCount(3, $next);
        $this->assertSame([ChangeLogFeedItemKind::ENTRY, ChangeLogFeedItemKind::ENTRY,
            ChangeLogFeedItemKind::RECEIPT], array_map(static fn($item) => $item->kind, $next));
        $this->assertSame($receiptId, $next[2]->receipt->id);
        $this->assertSame(52, $reader->feedCountBefore($filter,
            new ChangeLogFeedAnchor($next[2]->createdAt, $next[2]->kind, $receiptId)));
        $fromEnd = $reader->feedWindow($filter, null, TableAnchorDirection::Before, 2);
        $this->assertSame($next[1]->entry->id, $fromEnd[0]->entry->id);
        $this->assertSame($receiptId, $fromEnd[1]->receipt->id);

        Database::sql("INSERT INTO {$database}.`hilos_change_log_field` (`table_id`, `name`)"
            . " VALUES (?, 'tie_long')", [$tableId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_field`"
            . " WHERE `table_id` = ? AND `name` = 'tie_long'", [$tableId]);
        $fieldId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_change`"
            . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`)'
            . " VALUES (?, ?, ?, 'long', 1, 1)", [$moment, $next[1]->entry->id, $fieldId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_change`"
            . ' WHERE `log_id` = ? AND `field_id` = ?', [$next[1]->entry->id, $fieldId]);
        $changeId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_value`"
            . ' (`created_at`, `change_id`, `old_value`, `new_value`) VALUES (?, ?, ?, ?)',
            [$moment, $changeId, 'long before', 'long after']);
        $bare = $reader->feedWindow($filter, null, TableAnchorDirection::Before, 2)[0]->entry;
        $this->assertSame([1], $bare->recordKey);
        $this->assertTrue($bare->changes[0]->bodyOmitted);
        $this->assertNull($bare->changes[0]->oldValue);
        $this->assertSame('long before', $reader->entry($bare->id)->changes[0]->oldValue);

        $historyFilter = new ChangeLogHistoryFilter(since: $at->modify('-1 second'));
        $historyFirst = $reader->historyWindow('hilos_setting', $historyFilter,
            true, null, TableAnchorDirection::After, 100);
        $this->assertCount(ChangeLogSectionReader::MAX_WINDOW_ROWS, $historyFirst);
        $historyLast = $historyFirst[array_key_last($historyFirst)]->entry;
        $historyNext = $reader->historyWindow('hilos_setting', $historyFilter, true,
            new ChangeLogHistoryAnchor($historyLast->createdAt, $historyLast->id), TableAnchorDirection::After, 50);
        $this->assertCount(3, $historyNext);
        $allIds = array_merge(
            array_map(static fn($row): int => $row->entry->id, $historyFirst),
            array_map(static fn($row): int => $row->entry->id, $historyNext),
        );
        $this->assertCount(53, array_unique($allIds));
        $byRecord = $reader->historyWindow('hilos_setting', new ChangeLogHistoryFilter(
            recordKey: [1], since: $at->modify('-1 second'),
        ), true, null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byRecord);
        $this->assertTrue($byRecord[0]->entry->changes[0]->bodyOmitted);
        $this->assertNull($byRecord[0]->entry->changes[0]->oldValue);

        Database::sql("INSERT INTO {$database}.`hilos_change_log`"
            . ' (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)'
            . " VALUES (?, NULL, ?, '[redacted]', NULL, 'delete')", [$moment, $tableId]);
        $masked = $reader->historyWindow('hilos_setting', $historyFilter,
            true, null, TableAnchorDirection::After, 1)[0]->entry;
        $this->assertNull($masked->recordKey);
        $this->assertSame(1, $reader->historyCount('hilos_setting', new ChangeLogHistoryFilter(recordKey: [1])));
    }

    public function testHistoryFiltersAndPaginatesRealJournalRows(): void
    {
        $actorId = $this->createUser('History Actor 1452');
        $since = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $key = self::KEY_PREFIX . 'history';
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            [$key, 'string', 'first']);
        Database::sql('SELECT `id` FROM `hilos_setting` WHERE `key` = ?', [$key]);
        $settingId = (int)Database::field('id');
        JournalReceiptScope::run(
            JournalReceiptData::web($actorId, null, 31, 'setting.update', 'history-test'),
            static function () use ($key): void {
                Database::sqlRun('UPDATE `hilos_setting` SET `type` = ?, `value` = ? WHERE `key` = ?',
                    ['boolean', '1', $key]);
                Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` = ?', [$key]);
            },
        );
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            [self::KEY_PREFIX . 'history-other', 'string', 'other']);

        $reader = new ChangeLogSectionReader();
        $filter = new ChangeLogHistoryFilter(since: $since);
        $newest = $reader->historyWindow('hilos_setting', $filter, true, null, TableAnchorDirection::After, 50);
        $this->assertCount(4, $newest);
        $this->assertSame(4, $reader->historyCount('hilos_setting', $filter));
        $this->assertSame(3, $reader->historyCount('hilos_setting', $filter, 2));
        $this->assertSame(['create', 'delete', 'update', 'create'], array_map(
            static fn($row): string => $row->entry->mutation, $newest,
        ));
        $this->assertNull($newest[0]->receipt);
        $this->assertSame('History Actor 1452', $newest[1]->receipt->actor->label);
        $this->assertSame('web', $newest[2]->receipt->channel);
        $this->assertSame(2, $reader->historyCountBefore('hilos_setting', $filter, true,
            new ChangeLogHistoryAnchor($newest[2]->entry->createdAt, $newest[2]->entry->id)));

        $older = $reader->historyWindow('hilos_setting', $filter, true,
            new ChangeLogHistoryAnchor($newest[0]->entry->createdAt, $newest[0]->entry->id),
            TableAnchorDirection::After, 2);
        $this->assertSame([$newest[1]->entry->id, $newest[2]->entry->id], array_map(
            static fn($row): int => $row->entry->id, $older,
        ));
        $oldest = $reader->historyWindow('hilos_setting', $filter, false, null, TableAnchorDirection::After, 50);
        $this->assertSame(array_reverse(array_map(static fn($row): int => $row->entry->id, $newest)),
            array_map(static fn($row): int => $row->entry->id, $oldest));
        $this->assertSame(1, $reader->historyCountBefore('hilos_setting', $filter, false,
            new ChangeLogHistoryAnchor($oldest[1]->entry->createdAt, $oldest[1]->entry->id)));

        $byRecord = new ChangeLogHistoryFilter(recordKey: [$settingId], since: $since);
        $this->assertSame(3, $reader->historyCount('hilos_setting', $byRecord));
        $this->assertSame(3, $reader->historyCount('hilos_setting',
            new ChangeLogHistoryFilter(recordKey: [(string)$settingId], since: $since)));
        $this->assertSame(3, $reader->historyCount('hilos_setting',
            new ChangeLogHistoryFilter(recordKey: ['000' . $settingId], since: $since)));
        $byField = $reader->historyWindow('hilos_setting', new ChangeLogHistoryFilter(
            field: 'type', since: $since,
        ), true, null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byField);
        $this->assertSame('update', $byField[0]->entry->mutation);
        $this->assertSame(2, $reader->historyCount('hilos_setting', new ChangeLogHistoryFilter(
            who: '#' . $actorId, since: $since,
        )));
        $this->assertSame(2, $reader->historyCount('hilos_setting', new ChangeLogHistoryFilter(
            who: 'Actor 1452', since: $since,
        )));
        $this->assertSame(0, $reader->historyCount('hilos_setting', new ChangeLogHistoryFilter(
            until: $since,
        )));
        $this->assertSame(0, $reader->historyCount('bot', new ChangeLogHistoryFilter()));
        $this->assertSame([], $reader->historyWindow('bot', new ChangeLogHistoryFilter(),
            true, null, TableAnchorDirection::After, 50));

        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$actorId]);
        $afterErase = $reader->historyWindow('hilos_setting', $filter, true, null, TableAnchorDirection::After, 50);
        $this->assertSame('Deleted user #' . $actorId, $afterErase[1]->receipt->actor->label);

        $this->expectException(InvalidArgumentException::class);
        $reader->historyCount('hilos_setting', new ChangeLogHistoryFilter(recordKey: []));
    }

    public function testMissingJournalSchemaPropagatesSqlFailure(): void
    {
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        try {
            Database::configure(
                index: DatabaseConnectionDefaults::PRIMARY_INDEX,
                host: $config->host,
                user: $config->user,
                password: $config->password,
                database: 'information_schema',
                port: $config->port,
                charset: $config->charset,
                socket: $config->socket,
                reconnectAttempts: $config->reconnectAttempts,
                reconnectDelay: $config->reconnectDelay,
            );
            $this->expectException(DatabaseException::class);
            (new ChangeLogSectionReader())->overview();
        } finally {
            Database::configure(
                index: DatabaseConnectionDefaults::PRIMARY_INDEX,
                host: $config->host,
                user: $config->user,
                password: $config->password,
                database: $config->database,
                port: $config->port,
                charset: $config->charset,
                socket: $config->socket,
                reconnectAttempts: $config->reconnectAttempts,
                reconnectDelay: $config->reconnectDelay,
            );
        }
    }

    /**
     * @param string $name Fixture person name
     * @return int Newly created person id
     */
    private function createUser(string $name): int
    {
        Database::sqlRun('INSERT INTO `hilos_user` (`name`, `admin`, `block`) VALUES (?, 0, 0)', [$name]);
        Database::sql('SELECT `id` FROM `hilos_user` WHERE `name` = ? ORDER BY `id` DESC LIMIT 1', [$name]);
        $id = (int)Database::field('id');
        $this->userIds[] = $id;
        return $id;
    }

    /** @return list<array<string, mixed>> Primary schema trigger definitions */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }
}
