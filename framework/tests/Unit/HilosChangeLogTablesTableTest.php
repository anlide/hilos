<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\ChangeLog\Section\ChangeLogTableOwner;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\HilosException;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTableRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The table list preserves declarations, exact windows and nullable display values. */
final class HilosChangeLogTablesTableTest extends TestCase
{
    public function testSummaryProjectsColumnModesAndUtcTimeWithoutRecordKey(): void
    {
        $row = HilosChangeLogTablesTableRow::fromSummary(new ChangeLogTableSummary(
            'hilos_setting', ChangeLogTableOwner::FRAMEWORK, true,
            [
                JournalColumnMode::VALUE->value => 2,
                JournalColumnMode::PERSONAL->value => 1,
                JournalColumnMode::BINARY->value => 3,
                JournalColumnMode::SECRET->value => 4,
                JournalColumnMode::NOISE->value => 5,
                JournalColumnMode::RECORD_KEY->value => 1,
            ],
            12, new DateTimeImmutable('2026-10-09T12:11:12.123456+02:00'),
        ));

        self::assertSame('hilos_setting', $row->getRowKey());
        self::assertSame(HilosChangeLogTablesTableRow::name, $row::keyField());
        self::assertSame('framework', $row->owner);
        self::assertTrue($row->journaled);
        self::assertSame(['value' => 2, 'personal' => 1, 'binary' => 3, 'secret' => 4, 'noise' => 5], $row->columns);
        self::assertSame(12, $row->changesLast24h);
        self::assertSame(12, $row->changesSort);
        self::assertSame('2026-10-09T10:11:12.123Z', $row->lastChangeAt);
        self::assertSame($row->lastChangeAt, $row->lastChangeSort);
        self::assertSame($row->toArray(), HilosChangeLogTablesTableRow::fromArray($row->toArray())->toArray());

        $table = new HilosChangeLogTablesTable();
        $envelope = $table->browserRow($row);
        self::assertSame('hilos_setting', $envelope[BrowserPageSignalData::rowKey]);
        self::assertSame(['table' => $row->toArray()], $envelope[BrowserPageSignalData::sources]);
        self::assertArrayNotHasKey('id', $row->toArray());
        self::assertArrayNotHasKey('rowKey', $row->toArray());
        self::assertSame(array_keys($row->toArray()), array_keys($table->wireFields()));
        self::assertSame(array_keys($row->columns), array_keys($table->wireFields()[HilosChangeLogTablesTableRow::columns]->fields));
        self::assertSame($row->toArray(), ViewerFields::hide(
            $row->toArray(), $table->wireFields(), static fn(string $collection, string $field): bool => false,
        ));
    }

    public function testNeverWrittenTableRetainsZeroActivityAndNoTime(): void
    {
        $row = HilosChangeLogTablesTableRow::fromSummary(new ChangeLogTableSummary(
            'new_table', ChangeLogTableOwner::PROJECT, true, [], 0, null,
        ));
        self::assertSame('project', $row->owner);
        self::assertSame(['value' => 0, 'personal' => 0, 'binary' => 0, 'secret' => 0, 'noise' => 0], $row->columns);
        self::assertSame(0, $row->changesLast24h);
        self::assertSame(0, $row->changesSort);
        self::assertNull($row->lastChangeAt);
        self::assertSame('', $row->lastChangeSort);
        self::assertSame($row->toArray(), HilosChangeLogTablesTableRow::fromArray($row->toArray())->toArray());
    }

    public function testUntrackedTableCarriesNoCountsOrTime(): void
    {
        $row = HilosChangeLogTablesTableRow::fromSummary(new ChangeLogTableSummary(
            'hilos_session', ChangeLogTableOwner::FRAMEWORK, false, [], null, null,
        ));
        self::assertFalse($row->journaled);
        self::assertNull($row->columns);
        self::assertNull($row->changesLast24h);
        self::assertNull($row->lastChangeAt);
        self::assertSame(-1, $row->changesSort);
        self::assertSame('', $row->lastChangeSort);
        self::assertSame($row->toArray(), HilosChangeLogTablesTableRow::fromArray($row->toArray())->toArray());
        self::assertSame($row->toArray(), ViewerFields::hide(
            $row->toArray(), new HilosChangeLogTablesTable()->wireFields(),
            static fn(string $collection, string $field): bool => false,
        ));
    }

    /** @param mixed $columns Malformed column-count payload */
    #[DataProvider('malformedColumns')]
    public function testMalformedColumnsAreRefused(mixed $columns): void
    {
        $payload = HilosChangeLogTablesTableRow::fromSummary(self::fixtures()[0])->toArray();
        $payload[HilosChangeLogTablesTableRow::columns] = $columns;
        $this->expectException(InvalidFormatException::class);
        HilosChangeLogTablesTableRow::fromArray($payload);
    }

    /** @return iterable<string, array{mixed}> Malformed count objects */
    public static function malformedColumns(): iterable
    {
        yield 'scalar' => ['broken'];
        yield 'list' => [[1, 2, 3, 4, 5]];
        yield 'missing mode' => [['value' => 1, 'personal' => 0, 'binary' => 0, 'secret' => 0]];
        yield 'string count' => [['value' => '1', 'personal' => 0, 'binary' => 0, 'secret' => 0, 'noise' => 0]];
        yield 'null count' => [['value' => 1, 'personal' => null, 'binary' => 0, 'secret' => 0, 'noise' => 0]];
    }

    public function testOpeningOrderFiltersAndViewerSearch(): void
    {
        $table = new ChangeLogTablesFixtureTable(self::fixtures());
        self::assertSame(25, $table->windowSize());
        self::assertNull($table->defaultSort());
        $all = $table->getPage(new TableQueryDTO());
        self::assertSame(['b_never', 'c_recent', 'd_tied', 'e_old', 'a_untracked', 'z_untracked'], self::names($all->rows));
        self::assertSame(6, $all->totalCount);
        self::assertTrue($all->totalExact);
        self::assertSame(0, $all->rowsBefore);
        $tracked = $table->getPage(new TableQueryDTO(filter: [HilosChangeLogTablesTable::FILTER_JOURNAL => 'tracked']));
        self::assertSame(['b_never', 'c_recent', 'd_tied', 'e_old'], self::names($tracked->rows));
        self::assertSame(4, $tracked->totalCount);
        $untracked = $table->getPage(new TableQueryDTO(filter: [HilosChangeLogTablesTable::FILTER_JOURNAL => 'untracked']));
        self::assertSame(['a_untracked', 'z_untracked'], self::names($untracked->rows));
        self::assertSame(2, $untracked->totalCount);
        self::assertSame(self::names($all->rows), self::names($table->getPage(new TableQueryDTO(
            filter: [HilosChangeLogTablesTable::FILTER_JOURNAL => ''],
        ))->rows));

        $shown = ViewerFields::shownNames($table->wireFields(), static fn(string $collection, string $field): bool => false);
        $found = $table->getPage(new TableQueryDTO(search: 'rack', shownFields: $shown));
        self::assertSame(['a_untracked', 'z_untracked'], self::names($found->rows));
        self::assertSame(2, $found->totalCount);
        self::assertSame(0, $table->getPage(new TableQueryDTO(search: 'missing'))->totalCount);
        self::assertSame([], $table->getPage(new TableQueryDTO(
            search: 'rack', filter: [HilosChangeLogTablesTable::FILTER_JOURNAL => 'tracked'],
        ))->rows);
    }

    /** @param mixed $journal Unknown filter value */
    #[DataProvider('invalidJournalFilters')]
    public function testUnknownJournalFilterIsRefused(mixed $journal): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChangeLogTablesFixtureTable(self::fixtures())->getPage(new TableQueryDTO(
            filter: [HilosChangeLogTablesTable::FILTER_JOURNAL => $journal],
        ));
    }

    /** @return iterable<string, array{mixed}> Unknown values, including mistyped ones */
    public static function invalidJournalFilters(): iterable
    {
        yield 'unknown string' => ['all'];
        yield 'number' => [1];
        yield 'boolean' => [false];
        yield 'array' => [['tracked']];
    }

    /**
     * @param string $field Sortable display field
     * @param string $direction Direction requested by a column sort
     * @param list<string> $expected Expected names, including ties by the row key
     */
    #[DataProvider('orders')]
    public function testSortsUseActivityKeysAndNameTies(string $field, string $direction, array $expected): void
    {
        $table = new ChangeLogTablesFixtureTable(self::fixtures());
        $sort = TableSortOrderDTO::of(new TableSortDTO($field, $direction));
        $all = $table->getPage(new TableQueryDTO(sort: $sort));
        self::assertSame($expected, self::names($all->rows));
        self::assertSame(6, $all->totalCount);
        $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2));
        $next = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, anchor: $first->lastAnchor));
        self::assertSame(array_slice($expected, 2, 2), self::names($next->rows));
        self::assertSame(2, $next->rowsBefore);
    }

    /** @return iterable<string, array{string, string, list<string>}> Both directions of every declared order */
    public static function orders(): iterable
    {
        yield 'name asc' => ['name', 'asc', ['a_untracked', 'b_never', 'c_recent', 'd_tied', 'e_old', 'z_untracked']];
        yield 'name desc' => ['name', 'desc', ['z_untracked', 'e_old', 'd_tied', 'c_recent', 'b_never', 'a_untracked']];
        yield 'activity asc' => ['changesLast24h', 'asc', ['a_untracked', 'z_untracked', 'b_never', 'c_recent', 'd_tied', 'e_old']];
        yield 'activity desc' => ['changesLast24h', 'desc', ['e_old', 'd_tied', 'c_recent', 'b_never', 'z_untracked', 'a_untracked']];
        yield 'time asc' => ['lastChangeAt', 'asc', ['a_untracked', 'b_never', 'z_untracked', 'e_old', 'c_recent', 'd_tied']];
        yield 'time desc' => ['lastChangeAt', 'desc', ['d_tied', 'c_recent', 'e_old', 'z_untracked', 'b_never', 'a_untracked']];
    }

    public function testNumberedWindowsCountTheWholeSetExactlyAndReadAgain(): void
    {
        $summaries = [];
        for ($index = 0; $index < 507; $index++) {
            $summaries[] = new ChangeLogTableSummary(
                sprintf('table_%03d', $index), ChangeLogTableOwner::PROJECT, true, [], 0, null,
            );
        }
        $table = new ChangeLogTablesFixtureTable(array_reverse($summaries));
        $second = $table->getPage(new TableQueryDTO(limit: $table->windowSize(), pageIndex: 1));
        self::assertSame(507, $second->totalCount);
        self::assertTrue($second->totalExact);
        self::assertSame(25, $second->rowsBefore);
        self::assertCount(25, $second->rows);
        self::assertSame('table_025', $second->rows[0]->name);
        self::assertSame('table_049', $second->rows[24]->name);
        $beyond = $table->getPage(new TableQueryDTO(limit: 25, pageIndex: 21));
        self::assertSame([], $beyond->rows);
        self::assertSame(507, $beyond->totalCount);

        $table->source = [self::fixtures()[0]];
        self::assertSame(1, $table->getPage(new TableQueryDTO())->totalCount);
    }

    public function testReaderFailurePropagatesInsteadOfServingAnEmptySet(): void
    {
        $table = new class extends HilosChangeLogTablesTable {
            /**
             * @return list<ChangeLogTableSummary> No summaries on a refused read
             * @throws HilosException Always, to reproduce a reader failure
             */
            protected function summaries(): array
            {
                throw new HilosException('Journal unavailable');
            }
        };
        $this->expectException(HilosException::class);
        $table->getPage(new TableQueryDTO());
    }

    /**
     * @param list<HilosChangeLogTablesTableRow> $rows Typed viewport rows
     * @return list<string> Table names in window order
     */
    private static function names(array $rows): array
    {
        return array_map(static fn(HilosChangeLogTablesTableRow $row): string => $row->name, $rows);
    }

    /** @return list<ChangeLogTableSummary> Unordered declarations with nulls, numeric and time ties */
    private static function fixtures(): array
    {
        return [
            new ChangeLogTableSummary('z_untracked', ChangeLogTableOwner::PROJECT, false, [], null, null),
            new ChangeLogTableSummary('d_tied', ChangeLogTableOwner::PROJECT, true, [], 2, new DateTimeImmutable('2026-10-10T12:00:00Z')),
            new ChangeLogTableSummary('b_never', ChangeLogTableOwner::FRAMEWORK, true, [], 0, null),
            new ChangeLogTableSummary('e_old', ChangeLogTableOwner::FRAMEWORK, true, [], 12, new DateTimeImmutable('2026-10-09T12:00:00Z')),
            new ChangeLogTableSummary('a_untracked', ChangeLogTableOwner::FRAMEWORK, false, [], null, null),
            new ChangeLogTableSummary('c_recent', ChangeLogTableOwner::PROJECT, true, [], 2, new DateTimeImmutable('2026-10-10T12:00:00Z')),
        ];
    }
}

/** Pure fixture source for the framework table's protected reader seam. */
final class ChangeLogTablesFixtureTable extends HilosChangeLogTablesTable
{
    /** @param list<ChangeLogTableSummary> $source Table summaries for the next request */
    public function __construct(public array $source)
    {
        parent::__construct();
    }

    /** @return list<ChangeLogTableSummary> Fixture declarations without SQL */
    protected function summaries(): array
    {
        return $this->source;
    }
}
