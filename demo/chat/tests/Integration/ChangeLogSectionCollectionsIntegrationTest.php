<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Demo\Chat\Database\Database;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItemKind;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogNoisyColumn;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\UnplacedJournalColumnException;
use Hilos\Database\Migration;
use Hilos\Database\Object\Collection\ChangeLogEntries as ObjectChangeLogEntries;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournaledTables;
use Hilos\Database\View\Collection\ChangeLogEntries;
use Hilos\Hilos;

/** Section collections read real chat triggers across the two configured databases. */
final class ChangeLogSectionCollectionsIntegrationTest extends IntegrationTestCase
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
        $before = Hilos::$db->changeLogTables->sectionOverview();
        $this->assertGreaterThan(0, $before->journalBytes);
        $this->assertGreaterThan(0, $before->liveTables);
        $this->assertContains('hilos_setting', $before->journaledTables);
        $sorted = $before->journaledTables;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $before->journaledTables);
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
        $after = Hilos::$db->changeLogTables->sectionOverview();
        $this->assertSame($before->journalEntries + 1, $after->journalEntries);
        $this->assertNotNull($after->oldestAt);
        $this->assertSame($before->liveTables, $after->liveTables);

        $tables = [];
        foreach (Hilos::$db->changeLogTables->sectionTables() as $summary) {
            $tables[$summary->name] = $summary;
        }
        $this->assertSame($after->liveTables, count($tables));
        $this->assertSame([], array_diff($after->journaledTables, array_keys($tables)));
        $this->assertSame('framework', $tables['hilos_setting']->owner->value);
        $this->assertTrue($tables['hilos_setting']->journaled);
        $this->assertGreaterThanOrEqual(1, $tables['hilos_setting']->changesLast24h);
        $this->assertNotNull($tables['hilos_setting']->lastChangeAt);
        $this->assertSame(1, $tables['hilos_setting']->modeCounts[JournalColumnMode::RECORD_KEY->value]);
        foreach (['bot' => 12, 'moderator_prompt_piece' => 2] as $table => $valueColumns) {
            $this->assertSame('project', $tables[$table]->owner->value);
            $this->assertTrue($tables[$table]->journaled);
            $this->assertSame(1, $tables[$table]->modeCounts[JournalColumnMode::RECORD_KEY->value]);
            $this->assertSame($valueColumns, $tables[$table]->modeCounts[JournalColumnMode::VALUE->value]);
            $this->assertNotNull($tables[$table]->changesLast24h);
        }
        $this->assertFalse($tables['event']->journaled);
        $this->assertFalse($tables['event_message']->journaled);
    }

    public function testNoisyColumnsMatchLiveDeclarationsInTableAndColumnOrder(): void
    {
        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $expected = [];
        foreach (JournaledTables::mounted() as $entityClass) {
            if (!isset($schemas[$entityClass::_table])) {
                continue;
            }
            foreach ($entityClass::_journalNoise as $column => $reason) {
                $expected[$entityClass::_table . '.' . $column] = [
                    'table' => $entityClass::_table, 'column' => $column, 'reason' => $reason,
                ];
            }
        }
        ksort($expected, SORT_STRING);
        $this->assertNotEmpty($expected);
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        $noisyColumns = Hilos::$db->changeLogTables->sectionNoisyColumns();
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        $this->assertSame(array_values($expected), array_map(get_object_vars(...), $noisyColumns->toArray()));
        foreach ($noisyColumns as $column) {
            $this->assertInstanceOf(ChangeLogNoisyColumn::class, $column);
            $this->assertNotEmpty($column->reason);
            $this->assertNotContains($column->column, $schemas[$column->table]->primaryKey);
            foreach (JournaledTables::mounted() as $entityClass) {
                if ($entityClass::_table === $column->table) {
                    $this->assertNotContains($column->column, $entityClass::_journalSecrets);
                }
            }
        }
    }

    public function testProjectNoiseRequiresALiveTableAndRestoresConnectionOnPolicyFailure(): void
    {
        $originalContext = Hilos::$db;
        $context = new ChangeLogNoiseDbContext();
        $context->configure();
        $context->entities = $originalContext->getMountedEntities();
        $context->entities['noiseProbe'] = ChangeLogNoiseProbeEntity::class;
        Hilos::$db = $context;
        try {
            $this->assertNotContains(ChangeLogNoiseProbeEntity::_table, array_map(
                static fn(ChangeLogNoisyColumn $column): string => $column->table,
                Hilos::$db->changeLogTables->sectionNoisyColumns()->toArray(),
            ));
            Database::sql('CREATE TABLE `' . ChangeLogNoiseProbeEntity::_table . '`'
                . ' (`id` INT NOT NULL PRIMARY KEY, `noisy_counter` INT NOT NULL) ENGINE=InnoDB');
            Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
            $probeColumns = array_values(array_filter(
                Hilos::$db->changeLogTables->sectionNoisyColumns()->toArray(),
                static fn(ChangeLogNoisyColumn $column): bool => $column->table === ChangeLogNoiseProbeEntity::_table,
            ));
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
            $this->assertSame([[
                'table' => ChangeLogNoiseProbeEntity::_table,
                'column' => 'noisy_counter',
                'reason' => 'Fixture counter changes automatically',
            ]], array_map(get_object_vars(...), $probeColumns));

            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sql('ALTER TABLE `' . ChangeLogNoiseProbeEntity::_table . '` DROP COLUMN `noisy_counter`');
            Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
            try {
                Hilos::$db->changeLogTables->sectionNoisyColumns();
                $this->fail('An invalid live noise declaration must refuse the read');
            } catch (UnplacedJournalColumnException $exception) {
                $this->assertStringContainsString(ChangeLogNoiseProbeEntity::_table . '.noisy_counter', $exception->getMessage());
                $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
            }
        } finally {
            Hilos::$db = $originalContext;
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sql('DROP TABLE IF EXISTS `' . ChangeLogNoiseProbeEntity::_table . '`');
        }
    }

    public function testNoisyColumnsAreEmptyWithoutMountedJournaledEntities(): void
    {
        $originalContext = Hilos::$db;
        Hilos::$db = new ChangeLogNoiseDbContext();
        Hilos::$db->configure();
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            $this->assertSame([], Hilos::$db->changeLogTables->sectionNoisyColumns()->toArray());
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        } finally {
            Hilos::$db = $originalContext;
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    public function testTableUsesLiveColumnPlacementAndTriggerHeaders(): void
    {
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        $detail = Hilos::$db->changeLogTables->sectionTable('hilos_user');
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

        foreach (['bot', 'moderator_prompt_piece'] as $table) {
            $project = Hilos::$db->changeLogTables->sectionTable($table);
            $this->assertNotNull($project);
            $this->assertCount(3, $project->triggers);
            foreach ($project->triggers as $trigger) {
                $this->assertSame(95, $trigger->validFromMigration);
            }
            $this->assertSame(JournalColumnMode::RECORD_KEY, $project->columns[0]->mode);
            foreach (array_slice($project->columns, 1) as $column) {
                $this->assertSame(JournalColumnMode::VALUE, $column->mode);
            }
        }
        $untracked = Hilos::$db->changeLogTables->sectionTable('event');
        $this->assertNotNull($untracked);
        $this->assertSame([], $untracked->triggers);
        $this->assertNull($untracked->columns[0]->mode);
        $this->assertNull(Hilos::$db->changeLogTables->sectionTable('no_such_table'));
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
    }

    public function testFeedWindowsKeepReceiptAndBareEntryDistinct(): void
    {
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
        $feed = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50);
        $this->assertCount(2, $feed);
        $this->assertSame(ChangeLogFeedItemKind::ENTRY, $feed->get(0)->kind);
        $this->assertSame(ChangeLogFeedItemKind::RECEIPT, $feed->get(1)->kind);
        $this->assertSame(2, Hilos::$db->changeLogReceipts->feedCount($filter));
        $this->assertSame($feed->get(0)->entry->id, Hilos::$db->changeLogReceipts->feedWindow(
            $filter, null, TableAnchorDirection::After, 1, -1,
        )->get(0)->entry->id);
        $this->assertSame([], Hilos::$db->changeLogReceipts->feedWindow(
            $filter, null, TableAnchorDirection::After, 1, TableConstants::COUNT_CEILING + 1,
        )->toArray());
        $this->assertSame([$receiptId], array_map(
            static fn($item): int => $item->receipt->id,
            Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 1, 1)->toArray(),
        ));
        $this->assertSame([$feed->get(0)->entry->id], array_map(
            static fn($item): int => $item->entry->id,
            Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::Before, 1, 1)->toArray(),
        ));
        $this->assertSame(2, Hilos::$db->changeLogReceipts->feedCount($filter, 1));
        $this->assertSame(1, Hilos::$db->changeLogReceipts->feedCountBefore($filter,
            new ChangeLogFeedAnchor($feed->get(1)->createdAt, $feed->get(1)->kind, $receiptId)));
        $firstAnchor = new ChangeLogFeedAnchor($feed->get(0)->createdAt, $feed->get(0)->kind, $feed->get(0)->entry->id);
        $older = Hilos::$db->changeLogReceipts->feedWindow($filter, $firstAnchor, TableAnchorDirection::After, 1);
        $this->assertSame($receiptId, $older->get(0)->receipt->id);
        $newer = Hilos::$db->changeLogReceipts->feedWindow($filter,
            new ChangeLogFeedAnchor($feed->get(1)->createdAt, $feed->get(1)->kind, $receiptId),
            TableAnchorDirection::Before, 1);
        $this->assertSame($feed->get(0)->entry->id, $newer->get(0)->entry->id);

        $receipt = Hilos::$db->changeLogReceipts->sectionReceipt($receiptId);
        $this->assertNotNull($receipt);
        $this->assertSame(1, $receipt->entryCount);
        $this->assertSame('hilos_setting', $receipt->touched[0]->table);
        $this->assertSame('create', $receipt->touched[0]->single->mutation);
        $this->assertSame(0, $receipt->touched[0]->single->changedFields);
        $this->assertCount(1, Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 50));
        $this->assertSame('create', Hilos::$db->changeLogEntries->sectionEntry($feed->get(0)->entry->id)->mutation);

        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("INSERT INTO {$database}.`hilos_change_log_receipt` (`created_at`, `channel`, `action`)"
            . " VALUES (UTC_TIMESTAMP(6), 'web', 'empty')");
        $emptyId = Database::lastInsertId();
        $this->assertNull(Hilos::$db->changeLogReceipts->sectionReceipt($emptyId));
        $this->assertSame(2, Hilos::$db->changeLogReceipts->feedCount($filter));
        $this->assertSame([], Hilos::$db->changeLogReceipts->feedWindow(new ChangeLogFeedFilter(
            table: 'hilos_setting', until: $since), null, TableAnchorDirection::After, 50)->toArray());
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

        $receipt = Hilos::$db->changeLogReceipts->sectionReceipt($receiptId);
        $this->assertNotNull($receipt);
        $this->assertSame($actorId, $receipt->actor->userId);
        $this->assertSame('Section Actor 1452', $receipt->actor->label);
        $this->assertFalse($receipt->actor->deleted);
        $this->assertCount(1, Hilos::$db->changeLogReceipts->feedWindow(new ChangeLogFeedFilter(
            who: '#' . $actorId, table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50));
        $byName = Hilos::$db->changeLogReceipts->feedWindow(new ChangeLogFeedFilter(
            who: ' Actor 1452 ', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byName);
        $this->assertSame($receiptId, $byName->get(0)->receipt->id);
        $this->assertSame('Section Actor 1452', $byName->get(0)->receipt->actor->label);
        $this->assertSame('Deleted user #' . $subjectId, $byName->get(0)->receipt->subject->label);
        $this->assertTrue($byName->get(0)->receipt->subject->deleted);
        $this->assertSame([], Hilos::$db->changeLogReceipts->feedWindow(new ChangeLogFeedFilter(
            who: 'Actor%1452', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50)->toArray());
        $this->assertSame([], Hilos::$db->changeLogReceipts->feedWindow(new ChangeLogFeedFilter(
            who: 'Actor_1452', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50)->toArray());
        $byId = Hilos::$db->changeLogReceipts->feedWindow(new ChangeLogFeedFilter(
            who: '#' . $subjectId, table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byId);
        $this->assertSame($receiptId, $byId->get(0)->receipt->id);
        $this->assertCount(1, Hilos::$db->changeLogReceipts->feedWindow(new ChangeLogFeedFilter(
            who: '#000' . $actorId, table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50));
        $byChannel = Hilos::$db->changeLogReceipts->feedWindow(new ChangeLogFeedFilter(
            channel: 'web', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byChannel);
        $this->assertSame($receiptId, $byChannel->get(0)->receipt->id);
        $this->assertSame([], Hilos::$db->changeLogReceipts->feedWindow(new ChangeLogFeedFilter(
            channel: 'migration', table: 'hilos_setting', since: $since,
        ), null, TableAnchorDirection::After, 50)->toArray());

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
        $entries = Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 50);
        $this->assertCount(2, $entries);
        $this->assertSame(['update', 'delete'], array_map(static fn($entry): string => $entry->mutation, $entries->toArray()));
        $this->assertSame($entries->get(0)->id, Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 1)->get(0)->id);
        $this->assertSame($entries->get(1)->id, Hilos::$db->changeLogEntries->receiptEntries($receiptId, $entries->get(0)->id, 1)->get(0)->id);
        $this->assertSame([], Hilos::$db->changeLogEntries->receiptEntries($receiptId, $entries->get(1)->id, 1)->toArray());

        $changes = [];
        foreach ($entries->get(0)->changes as $change) {
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

        $deleteFields = array_column($entries->get(1)->changes, 'field');
        $this->assertContains('key', $deleteFields);
        $this->assertContains('value', $deleteFields);
        $this->assertNull(Hilos::$db->changeLogReceipts->sectionReceipt(999999999));
        $receipt = Hilos::$db->changeLogReceipts->sectionReceipt($receiptId);
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
        $createdAt = $entries->get(0)->createdAt->format('Y-m-d H:i:s.u');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_field` (`table_id`, `name`)"
            . " VALUES (?, 'synthetic_nullable')", [$tableId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_field`"
            . " WHERE `table_id` = ? AND `name` = 'synthetic_nullable'", [$tableId]);
        $nullableFieldId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_change`"
            . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)'
            . " VALUES (?, ?, ?, 'inline', 1, 0, NULL, NULL)", [$createdAt, $entries->get(0)->id, $nullableFieldId]);
        Database::sql("INSERT INTO {$database}.`hilos_change_log_change`"
            . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`)'
            . " VALUES (?, ?, ?, 'long', 1, 1)", [$createdAt, $entries->get(0)->id, $fieldId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_change`"
            . ' WHERE `log_id` = ? AND `field_id` = ?', [$entries->get(0)->id, $fieldId]);
        $changeId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_value`"
            . ' (`created_at`, `change_id`, `old_value`, `new_value`) VALUES (?, ?, ?, ?)',
            [$createdAt, $changeId, str_repeat('old', 100), str_repeat('new', 100)]);
        $full = Hilos::$db->changeLogEntries->sectionEntry($entries->get(0)->id);
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

        $filter = new ChangeLogFeedFilter(table: 'hilos_setting', since: $at->modify('-1 second'));
        $first = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 100);
        $this->assertCount(ChangeLogEntries::MAX_WINDOW_ROWS, $first);
        $this->assertSame(53, Hilos::$db->changeLogReceipts->feedCount($filter));
        $this->assertSame(3, Hilos::$db->changeLogReceipts->feedCount($filter, 2));
        $last = $first->last();
        $next = Hilos::$db->changeLogReceipts->feedWindow($filter,
            new ChangeLogFeedAnchor($last->createdAt, $last->kind, $last->entry->id),
            TableAnchorDirection::After, 50);
        $this->assertCount(3, $next);
        $this->assertSame([ChangeLogFeedItemKind::ENTRY, ChangeLogFeedItemKind::ENTRY,
            ChangeLogFeedItemKind::RECEIPT], array_map(static fn($item) => $item->kind, $next->toArray()));
        $this->assertSame($receiptId, $next->get(2)->receipt->id);
        $this->assertSame(52, Hilos::$db->changeLogReceipts->feedCountBefore($filter,
            new ChangeLogFeedAnchor($next->get(2)->createdAt, $next->get(2)->kind, $receiptId)));
        $fromEnd = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::Before, 2);
        $this->assertSame($next->get(1)->entry->id, $fromEnd->get(0)->entry->id);
        $this->assertSame($receiptId, $fromEnd->get(1)->receipt->id);

        Database::sql("INSERT INTO {$database}.`hilos_change_log_field` (`table_id`, `name`)"
            . " VALUES (?, 'tie_long')", [$tableId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_field`"
            . " WHERE `table_id` = ? AND `name` = 'tie_long'", [$tableId]);
        $fieldId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_change`"
            . ' (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`)'
            . " VALUES (?, ?, ?, 'long', 1, 1)", [$moment, $next->get(1)->entry->id, $fieldId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_change`"
            . ' WHERE `log_id` = ? AND `field_id` = ?', [$next->get(1)->entry->id, $fieldId]);
        $changeId = (int)Database::field('id');
        Database::sql("INSERT INTO {$database}.`hilos_change_log_value`"
            . ' (`created_at`, `change_id`, `old_value`, `new_value`) VALUES (?, ?, ?, ?)',
            [$moment, $changeId, 'long before', 'long after']);
        $bare = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::Before, 2)->get(0)->entry;
        $this->assertSame([1], $bare->recordKey);
        $this->assertTrue($bare->changes[0]->bodyOmitted);
        $this->assertNull($bare->changes[0]->oldValue);
        $this->assertSame('long before', Hilos::$db->changeLogEntries->sectionEntry($bare->id)->changes[0]->oldValue);

        $historyFilter = new ChangeLogHistoryFilter(since: $at->modify('-1 second'));
        $historyFirst = Hilos::$db->changeLogEntries->historyWindow('hilos_setting', $historyFilter,
            true, null, TableAnchorDirection::After, 100);
        $this->assertCount(ChangeLogEntries::MAX_WINDOW_ROWS, $historyFirst);
        $historyLast = $historyFirst->last()->entry;
        $historyNext = Hilos::$db->changeLogEntries->historyWindow('hilos_setting', $historyFilter, true,
            new ChangeLogHistoryAnchor($historyLast->createdAt, $historyLast->id), TableAnchorDirection::After, 50);
        $this->assertCount(3, $historyNext);
        $allIds = array_merge(
            array_map(static fn($row): int => $row->entry->id, $historyFirst->toArray()),
            array_map(static fn($row): int => $row->entry->id, $historyNext->toArray()),
        );
        $this->assertCount(53, array_unique($allIds));
        $byRecord = Hilos::$db->changeLogEntries->historyWindow('hilos_setting', new ChangeLogHistoryFilter(
            recordKey: [1], since: $at->modify('-1 second'),
        ), true, null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byRecord);
        $this->assertTrue($byRecord->get(0)->entry->changes[0]->bodyOmitted);
        $this->assertNull($byRecord->get(0)->entry->changes[0]->oldValue);

        Database::sql("INSERT INTO {$database}.`hilos_change_log`"
            . ' (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)'
            . " VALUES (?, NULL, ?, '[redacted]', NULL, 'delete')", [$moment, $tableId]);
        $masked = Hilos::$db->changeLogEntries->historyWindow('hilos_setting', $historyFilter,
            true, null, TableAnchorDirection::After, 1)->first()->entry;
        $this->assertNull($masked->recordKey);
        $this->assertSame(1, Hilos::$db->changeLogEntries->historyCount('hilos_setting', new ChangeLogHistoryFilter(recordKey: [1])));
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

        $filter = new ChangeLogHistoryFilter(since: $since);
        $newest = Hilos::$db->changeLogEntries->historyWindow('hilos_setting', $filter, true, null, TableAnchorDirection::After, 50);
        $this->assertCount(4, $newest);
        $this->assertSame(4, Hilos::$db->changeLogEntries->historyCount('hilos_setting', $filter));
        $this->assertSame($newest->get(0)->entry->id, Hilos::$db->changeLogEntries->historyWindow(
            'hilos_setting', $filter, true, null, TableAnchorDirection::After, 1, -1,
        )->first()->entry->id);
        $this->assertSame([], Hilos::$db->changeLogEntries->historyWindow(
            'hilos_setting', $filter, true, null, TableAnchorDirection::After, 1, TableConstants::COUNT_CEILING + 1,
        )->toArray());
        $this->assertSame([$newest->get(1)->entry->id, $newest->get(2)->entry->id], array_map(
            static fn($row): int => $row->entry->id,
            Hilos::$db->changeLogEntries->historyWindow('hilos_setting', $filter, true, null, TableAnchorDirection::After, 2, 1)->toArray(),
        ));
        $this->assertSame([$newest->get(1)->entry->id, $newest->get(2)->entry->id], array_map(
            static fn($row): int => $row->entry->id,
            Hilos::$db->changeLogEntries->historyWindow('hilos_setting', $filter, true, null, TableAnchorDirection::Before, 2, 1)->toArray(),
        ));
        $this->assertSame(3, Hilos::$db->changeLogEntries->historyCount('hilos_setting', $filter, 2));
        $this->assertSame(['create', 'delete', 'update', 'create'], array_map(
            static fn($row): string => $row->entry->mutation, $newest->toArray(),
        ));
        $this->assertNull($newest->get(0)->receipt);
        $this->assertSame('History Actor 1452', $newest->get(1)->receipt->actor->label);
        $this->assertSame('web', $newest->get(2)->receipt->channel);
        $this->assertSame(2, Hilos::$db->changeLogEntries->historyCountBefore('hilos_setting', $filter, true,
            new ChangeLogHistoryAnchor($newest->get(2)->entry->createdAt, $newest->get(2)->entry->id)));

        $older = Hilos::$db->changeLogEntries->historyWindow('hilos_setting', $filter, true,
            new ChangeLogHistoryAnchor($newest->get(0)->entry->createdAt, $newest->get(0)->entry->id),
            TableAnchorDirection::After, 2);
        $this->assertSame([$newest->get(1)->entry->id, $newest->get(2)->entry->id], array_map(
            static fn($row): int => $row->entry->id, $older->toArray(),
        ));
        $oldest = Hilos::$db->changeLogEntries->historyWindow('hilos_setting', $filter, false, null, TableAnchorDirection::After, 50);
        $this->assertSame(array_reverse(array_map(static fn($row): int => $row->entry->id, $newest->toArray())),
            array_map(static fn($row): int => $row->entry->id, $oldest->toArray()));
        $this->assertSame(1, Hilos::$db->changeLogEntries->historyCountBefore('hilos_setting', $filter, false,
            new ChangeLogHistoryAnchor($oldest->get(1)->entry->createdAt, $oldest->get(1)->entry->id)));

        $byRecord = new ChangeLogHistoryFilter(recordKey: [$settingId], since: $since);
        $this->assertSame(3, Hilos::$db->changeLogEntries->historyCount('hilos_setting', $byRecord));
        $this->assertSame(3, Hilos::$db->changeLogEntries->historyCount('hilos_setting',
            new ChangeLogHistoryFilter(recordKey: [(string)$settingId], since: $since)));
        $this->assertSame(3, Hilos::$db->changeLogEntries->historyCount('hilos_setting',
            new ChangeLogHistoryFilter(recordKey: ['000' . $settingId], since: $since)));
        $byField = Hilos::$db->changeLogEntries->historyWindow('hilos_setting', new ChangeLogHistoryFilter(
            field: 'type', since: $since,
        ), true, null, TableAnchorDirection::After, 50);
        $this->assertCount(1, $byField);
        $this->assertSame('update', $byField->get(0)->entry->mutation);
        $this->assertSame(2, Hilos::$db->changeLogEntries->historyCount('hilos_setting', new ChangeLogHistoryFilter(
            who: '#' . $actorId, since: $since,
        )));
        $this->assertSame(2, Hilos::$db->changeLogEntries->historyCount('hilos_setting', new ChangeLogHistoryFilter(
            who: 'Actor 1452', since: $since,
        )));
        $this->assertSame(0, Hilos::$db->changeLogEntries->historyCount('hilos_setting', new ChangeLogHistoryFilter(
            until: $since,
        )));
        $this->assertSame(0, Hilos::$db->changeLogEntries->historyCount('event', new ChangeLogHistoryFilter()));
        $this->assertSame([], Hilos::$db->changeLogEntries->historyWindow('event', new ChangeLogHistoryFilter(),
            true, null, TableAnchorDirection::After, 50)->toArray());

        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$actorId]);
        $afterErase = Hilos::$db->changeLogEntries->historyWindow('hilos_setting', $filter, true, null, TableAnchorDirection::After, 50);
        $this->assertSame('Deleted user #' . $actorId, $afterErase->get(1)->receipt->actor->label);
        $this->assertTrue($afterErase->get(1)->receipt->actor->deleted);

        $this->expectException(InvalidArgumentException::class);
        Hilos::$db->changeLogEntries->historyCount('hilos_setting', new ChangeLogHistoryFilter(recordKey: []));
    }

    public function testFeedSkipWithAnAnchorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Hilos::$db->changeLogReceipts->feedWindow(
            new ChangeLogFeedFilter(),
            new ChangeLogFeedAnchor(new DateTimeImmutable('now', new DateTimeZone('UTC')), ChangeLogFeedItemKind::ENTRY, 1),
            TableAnchorDirection::After,
            1,
            1,
        );
    }

    public function testHistorySkipWithAnAnchorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Hilos::$db->changeLogEntries->historyWindow(
            'hilos_setting',
            new ChangeLogHistoryFilter(),
            true,
            new ChangeLogHistoryAnchor(new DateTimeImmutable('now', new DateTimeZone('UTC')), 1),
            TableAnchorDirection::After,
            1,
            1,
        );
    }

    public function testMissingJournalSchemaPropagatesSqlFailure(): void
    {
        $originalEntries = Hilos::$db->changeLogEntries->getObjectCollection();
        Hilos::$db->changeLogEntries->setObjectCollection(MissingSectionOverviewEntryObjects::initDB(Objects::LAZY_STRATEGY_KEY));
        try {
            $this->expectException(DatabaseException::class);
            Hilos::$db->changeLogTables->sectionOverview();
        } finally {
            Hilos::$db->changeLogEntries->setObjectCollection($originalEntries);
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

/** Supplies only mounted metadata; every replacement is scoped to one test's finally block. */
final class ChangeLogNoiseDbContext extends HilosDbContext
{
    /** @var array<string, class-string<Entity>> Mounted project and fixture Entity declarations */
    public array $entities = [];

    /** @return array<string, class-string<Entity>> Fixture's mounted declarations */
    public function getMountedEntities(): array
    {
        return $this->entities;
    }
}

/** Journaled project counter used only with a temporary live table. */
final class ChangeLogNoiseProbeEntity extends Entity
{
    public const string _table = 'hilos_change_log_noise_probe';
    public const bool _journaled = true;
    public const string _primary = 'id';
    public const array _columns = ['id', 'noisy_counter'];
    public const array _types = ['id' => PhpType::INTEGER->value, 'noisy_counter' => PhpType::INTEGER->value];
    public const array _journalNoise = ['noisy_counter' => 'Fixture counter changes automatically'];
    public const array _pii = [];
    public const array _piiNotPersonal = ['id', 'noisy_counter'];

    public int $id;
    public int $noisy_counter;
}

/** Keeps the journal binding while making the overview read a missing entry table without DDL. */
final class MissingSectionOverviewEntryObjects extends ObjectChangeLogEntries
{
    /** @return string Absent journal table for the SQL failure scenario */
    public function getTableName(): string
    {
        return 'hilos_missing_section_overview_entry';
    }
}
