<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Database;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTableRow;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTableRow;

/** The two registered viewport tables read real journal rows through the section reader. */
final class ChangeLogTablesIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.tables.hil1457.';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Journal row high-water marks before each case */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Triggers present before each case */
    private array $originalTriggers = [];

    /** @var ?int Fixture person to remove after triggers are dropped */
    private ?int $actorId = null;

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
        if ($this->actorId !== null) {
            Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$this->actorId]);
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

    public function testFeedAndHistoryServeRealWindowsFiltersAndSorts(): void
    {
        Database::sqlRun('INSERT INTO `hilos_user` (`name`, `admin`, `block`) VALUES (?, 0, 0)', ['Table Actor 1457']);
        $this->actorId = Database::lastInsertId();
        $key = self::KEY_PREFIX . 'record';
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)', [$key, 'string', 'first']);
        Database::sql('SELECT `id` FROM `hilos_setting` WHERE `key` = ?', [$key]);
        $recordId = (int)Database::field('id');
        $receiptIds = [];
        foreach (['second', 'third', 'fourth', 'fifth'] as $value) {
            $receiptIds[] = JournalReceiptScope::run(
                JournalReceiptData::web($this->actorId, null, 31, 'setting.update', 'table-test'),
                static function () use ($key, $value): ?int {
                    Database::sqlRun('UPDATE `hilos_setting` SET `value` = ? WHERE `key` = ?', [$value, $key]);
                    return JournalReceiptScope::currentId();
                },
            );
        }
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            [self::KEY_PREFIX . 'bare', 'string', 'outside']);

        $feed = new HilosChangeLogFeedTable();
        $filter = [
            HilosChangeLogFeedTable::FILTER_TABLE => 'hilos_setting',
            HilosChangeLogFeedTable::FILTER_PERIOD => 'hour',
        ];
        $query = new TableQueryDTO(search: '#' . $this->actorId, filter: $filter, limit: 2);
        $first = $feed->getPage($query);
        self::assertSame(4, $first->totalCount);
        self::assertTrue($first->totalExact);
        self::assertSame(0, $first->rowsBefore);
        self::assertSame(['receipt:' . $receiptIds[3], 'receipt:' . $receiptIds[2]], self::feedKeys($first->rows));
        self::assertSame(['createdAt', 'kind', 'id'], array_keys($first->lastAnchor->values));
        $next = $feed->getPage(new TableQueryDTO(
            search: '#' . $this->actorId, filter: $filter, limit: 2, anchor: $first->lastAnchor,
        ));
        self::assertSame(['receipt:' . $receiptIds[1], 'receipt:' . $receiptIds[0]], self::feedKeys($next->rows));
        self::assertSame(2, $next->rowsBefore);
        $previous = $feed->getPage(new TableQueryDTO(
            search: '#' . $this->actorId, filter: $filter, limit: 2,
            anchor: $next->firstAnchor, anchorDirection: TableAnchorDirection::Before,
        ));
        self::assertSame(self::feedKeys($first->rows), self::feedKeys($previous->rows));
        $numbered = $feed->getPage(new TableQueryDTO(search: '#' . $this->actorId, filter: $filter, limit: 2, pageIndex: 1));
        self::assertSame(self::feedKeys($next->rows), self::feedKeys($numbered->rows));
        self::assertSame(2, $numbered->rowsBefore);
        self::assertSame([], $feed->getPage(new TableQueryDTO(
            search: '#' . $this->actorId, filter: $filter, limit: 2, pageIndex: 3,
        ))->rows);
        self::assertSame(4, $feed->getPage(new TableQueryDTO(
            search: '#' . $this->actorId,
            filter: $filter + [HilosChangeLogFeedTable::FILTER_CHANNEL => 'web'],
        ))->totalCount);
        $unfiltered = $feed->getPage(new TableQueryDTO(filter: $filter, limit: 50));
        self::assertContains('entry', array_map(static fn($row): string => $row->kind, $unfiltered->rows));

        $history = new HilosChangeLogHistoryTable();
        $historyFilter = [
            HilosChangeLogHistoryTable::FILTER_TABLE => 'hilos_setting',
            HilosChangeLogHistoryTable::FILTER_RECORD => [$recordId],
            HilosChangeLogHistoryTable::FILTER_PERIOD => 'hour',
        ];
        $newest = $history->getPage(new TableQueryDTO(filter: $historyFilter, limit: 2));
        self::assertSame(5, $newest->totalCount);
        self::assertSame(0, $newest->rowsBefore);
        self::assertSame('update', $newest->rows[0]->mutation);
        $historyPage = $history->getPage(new TableQueryDTO(filter: $historyFilter, limit: 2, pageIndex: 2));
        self::assertCount(1, $historyPage->rows);
        self::assertSame(4, $historyPage->rowsBefore);
        self::assertSame('create', $historyPage->rows[0]->mutation);
        self::assertSame(5, $history->getPage(new TableQueryDTO(filter: [
            HilosChangeLogHistoryTable::FILTER_TABLE => 'hilos_setting',
            HilosChangeLogHistoryTable::FILTER_RECORD => [(string)$recordId],
        ]))->totalCount);
        self::assertSame(4, $history->getPage(new TableQueryDTO(filter: $historyFilter + [
            HilosChangeLogHistoryTable::FILTER_FIELD => 'value',
        ]))->totalCount);
        $oldest = $history->getPage(new TableQueryDTO(
            filter: $historyFilter,
            sort: TableSortOrderDTO::of(new TableSortDTO(HilosChangeLogHistoryTableRow::createdAt, TableConstants::ORDER_ASC)),
            limit: 2,
        ));
        self::assertSame('create', $oldest->rows[0]->mutation);
        self::assertSame(5, $oldest->totalCount);
    }

    public function testInvalidRecordLengthRefusesHistoryWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HilosChangeLogHistoryTable()->getPage(new TableQueryDTO(filter: [
            HilosChangeLogHistoryTable::FILTER_TABLE => 'hilos_setting',
            HilosChangeLogHistoryTable::FILTER_RECORD => [],
        ]));
    }

    /**
     * @param list<HilosChangeLogFeedTableRow> $rows Feed rows
     * @return list<string> Browser row keys in window order
     */
    private static function feedKeys(array $rows): array
    {
        return array_map(static fn(HilosChangeLogFeedTableRow $row): string => $row->rowKey, $rows);
    }

    /** @return list<array<string, mixed>> Primary schema trigger definitions */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }
}
