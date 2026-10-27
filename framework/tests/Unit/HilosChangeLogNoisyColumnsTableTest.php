<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\ViewerFields;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\ChangeLog\Section\ChangeLogNoisyColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\HilosException;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTableRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Noise declarations retain their inline identity and the standard viewport semantics. */
final class HilosChangeLogNoisyColumnsTableTest extends TestCase
{
    public function testProjectionRoundtripAndBrowserEnvelopeHaveNoEntityId(): void
    {
        $row = HilosChangeLogNoisyColumnsTableRow::fromColumn(new ChangeLogNoisyColumn(
            'hilos_user', 'last_activity', 'Changes on every request',
        ));
        $expected = [
            'columnKey' => 'hilos_user.last_activity',
            'table' => 'hilos_user',
            'column' => 'last_activity',
            'reason' => 'Changes on every request',
        ];
        self::assertSame($expected, $row->toArray());
        self::assertSame('hilos_user.last_activity', $row->getRowKey());
        self::assertSame('columnKey', $row::keyField());
        self::assertSame($expected, HilosChangeLogNoisyColumnsTableRow::fromArray($expected)->toArray());

        $table = new HilosChangeLogNoisyColumnsTable();
        self::assertInstanceOf(ViewportTable::class, $table);
        self::assertSame('hilosChangeLogNoisyColumns', $table::TABLE);
        self::assertSame(HilosChangeLogNoisyColumnsTableRow::class, $table->getRowClass());
        self::assertSame([
            'rowKey' => 'hilos_user.last_activity',
            'sources' => ['noisyColumn' => $expected],
        ], $table->browserRow($row));
        self::assertArrayNotHasKey('id', $row->toArray());
        self::assertArrayNotHasKey('rowKey', $row->toArray());
    }

    /** @param array<string, mixed> $payload Declaration with one malformed required field */
    #[DataProvider('malformedRows')]
    public function testMalformedRequiredFieldsAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosChangeLogNoisyColumnsTableRow::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing, null and mistyped required fields */
    public static function malformedRows(): iterable
    {
        $valid = ['columnKey' => 'hilos_user.activity', 'table' => 'hilos_user', 'column' => 'activity', 'reason' => 'Frequent writes'];
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield $field . ' missing' => [$missing];
            yield $field . ' null' => [array_replace($valid, [$field => null])];
            yield $field . ' number' => [array_replace($valid, [$field => 1])];
        }
    }

    public function testAllFourFieldsAreNotPersonalSchemaMetadata(): void
    {
        $table = new HilosChangeLogNoisyColumnsTable();
        $payload = HilosChangeLogNoisyColumnsTableRow::fromColumn(self::fixtures()[0])->toArray();
        self::assertSame(['columnKey', 'table', 'column', 'reason'], array_keys($table->wireFields()));
        foreach ($table->wireFields() as $field) {
            self::assertEquals(WireField::notPersonal(), $field);
        }
        self::assertSame($payload, ViewerFields::hide(
            $payload, $table->wireFields(), static fn(string $collection, string $field): bool => false,
        ));
    }

    public function testOpeningOrderIsLexicalByTableThenColumnAndKeysAreQualified(): void
    {
        $table = new ChangeLogNoisyColumnsFixtureTable(self::fixtures());
        self::assertSame(25, $table->windowSize());
        self::assertNull($table->defaultSort());
        $snapshot = $table->getPage(new TableQueryDTO());
        self::assertSame([
            'A_table.activity', 'table_10.activity', 'table_2.Activity', 'table_2.activity', 'table_2.activity_10', 'table_2.activity_2',
        ], self::keys($snapshot->rows));
        self::assertSame(6, $snapshot->totalCount);
        self::assertTrue($snapshot->totalExact);
        self::assertSame(0, $snapshot->rowsBefore);
        self::assertCount(6, array_unique(self::keys($snapshot->rows)));
        self::assertSame('activity', $snapshot->rows[0]->column);
        self::assertSame('activity', $snapshot->rows[1]->column);
        self::assertNotSame($table->browserRow($snapshot->rows[0]), $table->browserRow($snapshot->rows[1]));
    }

    public function testWindowsCountTheWholeSetAndCarryColumnKeyAnchorsInBothDirections(): void
    {
        $source = [];
        for ($index = 0; $index < 507; $index++) {
            $source[] = new ChangeLogNoisyColumn(sprintf('table_%03d', $index), 'activity', 'Frequent writes');
        }
        $table = new ChangeLogNoisyColumnsFixtureTable(array_reverse($source));
        $first = $table->getPage(new TableQueryDTO(limit: $table->windowSize()));
        self::assertCount(25, $first->rows);
        self::assertSame(25, $first->limit);
        self::assertSame(507, $first->totalCount);
        self::assertTrue($first->totalExact);
        self::assertSame(0, $first->rowsBefore);
        self::assertSame(['columnKey' => 'table_000.activity'], $first->firstAnchor?->toArray());
        self::assertSame(['columnKey' => 'table_024.activity'], $first->lastAnchor?->toArray());
        self::assertNull($first->frame?->before);
        self::assertSame(['columnKey' => 'table_025.activity'], $first->frame?->after?->toArray());

        $next = $table->getPage(new TableQueryDTO(limit: 25, anchor: $first->lastAnchor));
        self::assertCount(25, $next->rows);
        self::assertSame(25, $next->rowsBefore);
        self::assertSame(507, $next->totalCount);
        self::assertTrue($next->totalExact);
        self::assertSame(['columnKey' => 'table_025.activity'], $next->firstAnchor?->toArray());
        self::assertSame(['columnKey' => 'table_049.activity'], $next->lastAnchor?->toArray());
        self::assertSame($first->lastAnchor?->toArray(), $next->frame?->before?->toArray());
        self::assertSame(['columnKey' => 'table_050.activity'], $next->frame?->after?->toArray());
        $previous = $table->getPage(new TableQueryDTO(
            limit: 25, anchor: $next->firstAnchor, anchorDirection: TableAnchorDirection::Before,
        ));
        self::assertSame(self::keys($first->rows), self::keys($previous->rows));
        self::assertSame(0, $previous->rowsBefore);
        $numbered = $table->getPage(new TableQueryDTO(limit: 25, pageIndex: 1));
        self::assertSame(self::keys($next->rows), self::keys($numbered->rows));
        self::assertSame(25, $numbered->rowsBefore);

        $last = $table->getPage(new TableQueryDTO(limit: 25, pageIndex: 20));
        self::assertCount(7, $last->rows);
        self::assertSame(500, $last->rowsBefore);
        self::assertSame(['columnKey' => 'table_500.activity'], $last->firstAnchor?->toArray());
        self::assertSame(['columnKey' => 'table_506.activity'], $last->lastAnchor?->toArray());
        self::assertNull($last->frame?->after);
        $afterLast = $table->getPage(new TableQueryDTO(limit: 25, anchor: $last->lastAnchor));
        self::assertSame([], $afterLast->rows);
        self::assertSame(507, $afterLast->totalCount);
        self::assertTrue($afterLast->totalExact);
        self::assertSame(507, $afterLast->rowsBefore);
        self::assertNull($afterLast->firstAnchor);
        self::assertNull($afterLast->lastAnchor);
        self::assertNull($afterLast->frame);
        $beyond = $table->getPage(new TableQueryDTO(limit: 25, pageIndex: 21));
        self::assertSame([], $beyond->rows);
        self::assertSame(507, $beyond->totalCount);
        self::assertSame(525, $beyond->rowsBefore);
        self::assertCount(50, $table->getPage(new TableQueryDTO(limit: 50))->rows);
    }

    public function testEmptySourceIsAnExactEmptyWindow(): void
    {
        $snapshot = new ChangeLogNoisyColumnsFixtureTable([])->getPage(new TableQueryDTO(limit: 25));
        self::assertSame([], $snapshot->rows);
        self::assertSame(0, $snapshot->totalCount);
        self::assertTrue($snapshot->totalExact);
        self::assertSame(25, $snapshot->limit);
        self::assertSame(0, $snapshot->rowsBefore);
        self::assertNull($snapshot->firstAnchor);
        self::assertNull($snapshot->lastAnchor);
        self::assertNull($snapshot->frame);
    }

    public function testEveryRequestReadsTheSourceAgainWithoutChangingThePreviousSnapshot(): void
    {
        $table = new ChangeLogNoisyColumnsFixtureTable(self::fixtures());
        $first = $table->getPage(new TableQueryDTO(limit: 1));
        self::assertSame(1, $table->reads);
        $table->source = [new ChangeLogNoisyColumn('new_table', 'counter', 'Updated declaration')];
        $second = $table->getPage(new TableQueryDTO(limit: 25));
        self::assertSame(2, $table->reads);
        self::assertSame(['new_table.counter'], self::keys($second->rows));
        self::assertSame('Updated declaration', $second->rows[0]->reason);
        self::assertSame(1, $second->totalCount);
        self::assertSame(['A_table.activity'], self::keys($first->rows));
        self::assertSame(6, $first->totalCount);
        $table->source = [];
        self::assertSame(0, $table->getPage(new TableQueryDTO())->totalCount);
        self::assertSame(3, $table->reads);
    }

    public function testReaderFailurePropagatesInsteadOfBecomingAnEmptyWindow(): void
    {
        $table = new class extends HilosChangeLogNoisyColumnsTable {
            /** @return ChangeLogReadCollection<ChangeLogNoisyColumn> No declarations on a refused read */
            protected function noisyColumns(): ChangeLogReadCollection
            {
                throw new HilosException('Journal metadata unavailable');
            }
        };
        $this->expectException(HilosException::class);
        $table->getPage(new TableQueryDTO());
    }

    public function testSearchCannotInjectItsOwnFieldDeclaration(): void
    {
        $table = new ChangeLogNoisyColumnsFixtureTable(self::fixtures());
        $this->expectException(TableSearchNotSupportedException::class);
        try {
            $table->getPage(new TableQueryDTO(search: 'activity', searchableFields: ['column' => 'column']));
        } finally {
            self::assertSame(0, $table->reads);
        }
    }

    public function testUndeclaredCompositeOrderIsDroppedByTheStandardGate(): void
    {
        $table = new ChangeLogNoisyColumnsFixtureTable(self::fixtures());
        $snapshot = $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(
            new TableSortDTO('table', 'desc'), new TableSortDTO('column', 'desc'),
        )));
        self::assertSame([
            'A_table.activity', 'table_10.activity', 'table_2.Activity', 'table_2.activity', 'table_2.activity_10', 'table_2.activity_2',
        ], self::keys($snapshot->rows));
        self::assertSame(['columnKey' => 'A_table.activity'], $snapshot->firstAnchor?->toArray());
    }

    public function testEmptySortVocabularyRetainsTheBaseSingleFieldSortAndKeyTieBreaker(): void
    {
        $table = new ChangeLogNoisyColumnsFixtureTable(self::fixtures());
        $sort = TableSortOrderDTO::of(new TableSortDTO('reason', 'desc'));
        $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2));
        self::assertSame(['table_2.activity_2', 'table_2.activity_10'], self::keys($first->rows));
        self::assertSame(['reason' => 'Frequent writes', 'columnKey' => 'table_2.activity_10'], $first->lastAnchor?->toArray());
        $next = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, anchor: $first->lastAnchor));
        self::assertSame(['table_2.activity', 'table_2.Activity'], self::keys($next->rows));
        self::assertSame(2, $next->rowsBefore);
    }

    public function testUndeclaredFiltersKeepTheSetAndSourceEventsProduceNoMutations(): void
    {
        $table = new ChangeLogNoisyColumnsFixtureTable(self::fixtures());
        self::assertSame(6, $table->getPage(new TableQueryDTO(filter: ['table' => 'missing']))->totalCount);
        self::assertNull($table->facetCounts(new TableQueryDTO(), ['table' => ['missing']]));
        self::assertSame([], $table::BROWSER);
        self::assertFalse(defined($table::class . '::SOURCE_TABLES'));
        foreach ([
            SourceChange::dbCreated('changeLogTables', '1', []),
            SourceChange::dbUpdated('changeLogTables', '1', []),
            SourceChange::dbDeleted('changeLogTables', '1'),
            SourceChange::dbCleared('changeLogTables'),
            SourceChange::rtCreated('unrelated', '1', []),
            SourceChange::rtUpdated('unrelated', '1', []),
            SourceChange::rtDeleted('unrelated', '1'),
        ] as $change) {
            self::assertNull($table->buildMutationForSourceEvent($change));
        }
        self::assertSame(1, $table->reads);
    }

    /**
     * @param list<HilosChangeLogNoisyColumnsTableRow> $rows Typed viewport rows
     * @return list<string> Qualified column keys in window order
     */
    private static function keys(array $rows): array
    {
        return array_map(static fn(HilosChangeLogNoisyColumnsTableRow $row): string => $row->getRowKey(), $rows);
    }

    /** @return list<ChangeLogNoisyColumn> Unordered declarations with repeated column names and lexical edge cases */
    private static function fixtures(): array
    {
        return [
            new ChangeLogNoisyColumn('table_2', 'activity_2', 'Frequent writes'),
            new ChangeLogNoisyColumn('table_2', 'activity', 'Frequent writes'),
            new ChangeLogNoisyColumn('table_10', 'activity', 'Frequent writes'),
            new ChangeLogNoisyColumn('A_table', 'activity', 'Frequent writes'),
            new ChangeLogNoisyColumn('table_2', 'activity_10', 'Frequent writes'),
            new ChangeLogNoisyColumn('table_2', 'Activity', 'Frequent writes'),
        ];
    }
}

/** Pure fixture for the framework table's request-local reader seam. */
final class ChangeLogNoisyColumnsFixtureTable extends HilosChangeLogNoisyColumnsTable
{
    public int $reads = 0;

    /** @param list<ChangeLogNoisyColumn> $source Noise declarations for the next request */
    public function __construct(public array $source)
    {
        parent::__construct();
    }

    /** @return ChangeLogReadCollection<ChangeLogNoisyColumn> Fresh fixture declarations without SQL */
    protected function noisyColumns(): ChangeLogReadCollection
    {
        $this->reads++;

        return ChangeLogReadCollection::fromArray($this->source);
    }
}
