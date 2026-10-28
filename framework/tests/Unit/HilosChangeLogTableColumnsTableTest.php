<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\ViewerFields;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\ChangeLog\Section\ChangeLogColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogTableDetail;
use Hilos\Database\ChangeLog\Section\ChangeLogTableOwner;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\HilosException;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTableRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Column windows preserve schema order and the identity of the selected table. */
final class HilosChangeLogTableColumnsTableTest extends TestCase
{
    public function testColdPresetComesFromTypedRouteParamsWithoutReadingMetadata(): void
    {
        $table = new ChangeLogTableColumnsFixtureTable([]);
        self::assertSame(['table' => 'sample'], $table->defaultFilter(new PageRouteParams(['tableId' => 'sample'])));
        self::assertSame(['table' => 'other'], $table->defaultFilter(new PageRouteParams(['tableId' => 'other'])));
        self::assertSame([], $table->requests);
    }

    public function testColdPresetRefusesAMissingRouteAddressWithoutReadingMetadata(): void
    {
        $table = new ChangeLogTableColumnsFixtureTable([]);
        $this->expectException(MissingPageRouteParamException::class);
        try {
            $table->defaultFilter(new PageRouteParams([]));
        } finally {
            self::assertSame([], $table->requests);
        }
    }

    public function testProjectionRoundtripEnvelopeAndNotPersonalFields(): void
    {
        $row = HilosChangeLogTableColumnsTableRow::fromColumn(new ChangeLogColumn(
            'last_activity', 'datetime', JournalColumnMode::NOISE, 'ignored', 'Changes on every request',
        ));
        $expected = [
            'name' => 'last_activity', 'sqlType' => 'datetime', 'mode' => 'noise',
            'storage' => 'ignored', 'noiseReason' => 'Changes on every request',
        ];
        self::assertSame($expected, $row->toArray());
        self::assertSame($expected, HilosChangeLogTableColumnsTableRow::fromArray($expected)->toArray());
        self::assertSame('last_activity', $row->getRowKey());
        self::assertSame('name', $row::keyField());

        $table = new HilosChangeLogTableColumnsTable();
        self::assertInstanceOf(ViewportTable::class, $table);
        self::assertSame('hilosChangeLogTableColumns', $table::TABLE);
        self::assertSame('table', $table::FILTER_TABLE);
        self::assertSame(HilosChangeLogTableColumnsTableRow::class, $table->getRowClass());
        self::assertSame(['rowKey' => 'last_activity', 'sources' => ['column' => $expected]], $table->browserRow($row));
        self::assertArrayNotHasKey('id', $row->toArray());
        self::assertArrayNotHasKey('rowKey', $row->toArray());
        self::assertSame(array_keys($expected), array_keys($table->wireFields()));
        foreach ($table->wireFields() as $field) {
            self::assertEquals(WireField::notPersonal(), $field);
        }
        self::assertSame($expected, ViewerFields::hide(
            $expected, $table->wireFields(), static fn(string $collection, string $field): bool => false,
        ));
    }

    public function testEveryJournalModeStorageAndNullSurviveTheProjection(): void
    {
        $source = [
            new ChangeLogColumn('id', 'bigint', JournalColumnMode::RECORD_KEY, null, null),
            new ChangeLogColumn('title', 'varchar', JournalColumnMode::VALUE, 'inline', null),
            new ChangeLogColumn('body', 'longtext', JournalColumnMode::VALUE, 'long', null),
            new ChangeLogColumn('email', 'varchar', JournalColumnMode::PERSONAL, 'fact', null),
            new ChangeLogColumn('token', 'varchar', JournalColumnMode::SECRET, 'fact', null),
            new ChangeLogColumn('image', 'blob', JournalColumnMode::BINARY, 'fact', null),
            new ChangeLogColumn('activity', 'datetime', JournalColumnMode::NOISE, 'ignored', 'Frequent writes'),
            new ChangeLogColumn('untracked', 'int', null, null, null),
        ];
        $rows = new ChangeLogTableColumnsFixtureTable(['sample' => self::detail('sample', $source)])
            ->getPage(new TableQueryDTO(filter: ['table' => 'sample']))->rows;
        self::assertSame(['record_key', 'value', 'value', 'personal', 'secret', 'binary', 'noise', null],
            array_map(static fn(HilosChangeLogTableColumnsTableRow $row): ?string => $row->mode, $rows));
        self::assertSame([null, 'inline', 'long', 'fact', 'fact', 'fact', 'ignored', null],
            array_map(static fn(HilosChangeLogTableColumnsTableRow $row): ?string => $row->storage, $rows));
        foreach ($rows as $index => $row) {
            self::assertSame($source[$index]->name, $row->name);
            self::assertSame($source[$index]->sqlType, $row->sqlType);
            self::assertSame($source[$index]->noiseReason, $row->noiseReason);
            self::assertSame($row->toArray(), $row::fromArray($row->toArray())->toArray());
        }
    }

    /** @param array<string, mixed> $payload Column metadata with one broken field */
    #[DataProvider('malformedRows')]
    public function testMalformedRowFieldsAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosChangeLogTableColumnsTableRow::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Required fields and nullable string types */
    public static function malformedRows(): iterable
    {
        $valid = ['name' => 'id', 'sqlType' => 'bigint', 'mode' => 'record_key', 'storage' => null, 'noiseReason' => null];
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield $field . ' missing' => [$missing];
            yield $field . ' integer' => [array_replace($valid, [$field => 1])];
            yield $field . ' boolean' => [array_replace($valid, [$field => false])];
            yield $field . ' array' => [array_replace($valid, [$field => []])];
        }
        yield 'null name' => [array_replace($valid, ['name' => null])];
        yield 'null sqlType' => [array_replace($valid, ['sqlType' => null])];
    }

    public function testOpeningOrderIsTheSchemaOrderRatherThanTheColumnNameOrder(): void
    {
        $table = new ChangeLogTableColumnsFixtureTable(['sample' => self::detail('sample', [
            new ChangeLogColumn('z_first', 'int', null, null, null),
            new ChangeLogColumn('id', 'bigint', null, null, null),
            new ChangeLogColumn('a_last', 'text', null, null, null),
        ])]);
        self::assertSame(25, $table->windowSize());
        self::assertNull($table->defaultSort());
        $snapshot = $table->getPage(new TableQueryDTO(limit: 25, filter: ['table' => 'sample']));
        self::assertSame(['z_first', 'id', 'a_last'], self::keys($snapshot->rows));
        self::assertSame(3, $snapshot->totalCount);
        self::assertTrue($snapshot->totalExact);
        self::assertSame(0, $snapshot->rowsBefore);
    }

    public function testTwentyFiveRowWindowsAnchorsInBothDirectionsAndPageJumps(): void
    {
        $columns = [];
        for ($index = 56; $index >= 0; $index--) {
            $columns[] = new ChangeLogColumn(sprintf('column_%02d', $index), 'int', null, null, null);
        }
        $table = new ChangeLogTableColumnsFixtureTable(['sample' => self::detail('sample', $columns)]);
        $query = new TableQueryDTO(limit: $table->windowSize(), filter: ['table' => 'sample']);
        $first = $table->getPage($query);
        self::assertCount(25, $first->rows);
        self::assertSame(25, $first->limit);
        self::assertSame(57, $first->totalCount);
        self::assertTrue($first->totalExact);
        self::assertSame(0, $first->rowsBefore);
        self::assertSame(['name' => 'column_56'], $first->firstAnchor?->toArray());
        self::assertSame(['name' => 'column_32'], $first->lastAnchor?->toArray());
        self::assertNull($first->frame?->before);
        self::assertSame(['name' => 'column_31'], $first->frame?->after?->toArray());

        $next = $table->getPage(new TableQueryDTO(limit: 25, filter: $query->filter, anchor: $first->lastAnchor));
        self::assertCount(25, $next->rows);
        self::assertSame(25, $next->rowsBefore);
        self::assertSame(57, $next->totalCount);
        self::assertTrue($next->totalExact);
        self::assertSame(['name' => 'column_31'], $next->firstAnchor?->toArray());
        self::assertSame(['name' => 'column_07'], $next->lastAnchor?->toArray());
        self::assertSame($first->lastAnchor?->toArray(), $next->frame?->before?->toArray());
        self::assertSame(['name' => 'column_06'], $next->frame?->after?->toArray());
        $previous = $table->getPage(new TableQueryDTO(
            limit: 25, filter: $query->filter, anchor: $next->firstAnchor, anchorDirection: TableAnchorDirection::Before,
        ));
        self::assertSame(self::keys($first->rows), self::keys($previous->rows));
        self::assertSame(0, $previous->rowsBefore);
        $numbered = $table->getPage(new TableQueryDTO(limit: 25, filter: $query->filter, pageIndex: 1));
        self::assertSame(self::keys($next->rows), self::keys($numbered->rows));
        self::assertSame(25, $numbered->rowsBefore);

        $last = $table->getPage(new TableQueryDTO(limit: 25, filter: $query->filter, pageIndex: 2));
        self::assertCount(7, $last->rows);
        self::assertSame(50, $last->rowsBefore);
        self::assertSame(['name' => 'column_06'], $last->firstAnchor?->toArray());
        self::assertSame(['name' => 'column_00'], $last->lastAnchor?->toArray());
        self::assertNull($last->frame?->after);
        $afterLast = $table->getPage(new TableQueryDTO(limit: 25, filter: $query->filter, anchor: $last->lastAnchor));
        self::assertSame([], $afterLast->rows);
        self::assertSame(57, $afterLast->rowsBefore);
        self::assertSame(57, $afterLast->totalCount);
        self::assertTrue($afterLast->totalExact);
        self::assertNull($afterLast->firstAnchor);
        self::assertNull($afterLast->lastAnchor);
        self::assertNull($afterLast->frame);
        $beyond = $table->getPage(new TableQueryDTO(limit: 25, filter: $query->filter, pageIndex: 3));
        self::assertSame([], $beyond->rows);
        self::assertSame(75, $beyond->rowsBefore);
        self::assertSame(57, $beyond->totalCount);
    }

    public function testChangingTheTablePresetAndReusingAnOldAnchorNeverReturnsOldRows(): void
    {
        $table = new ChangeLogTableColumnsFixtureTable([
            'first' => self::detail('first', [new ChangeLogColumn('old', 'text', JournalColumnMode::VALUE, 'long', null)]),
            'second' => self::detail('second', [new ChangeLogColumn('fresh', 'bigint', null, null, null)]),
        ]);
        $first = $table->getPage(new TableQueryDTO(limit: 25, filter: ['table' => 'first']));
        $second = $table->getPage(new TableQueryDTO(limit: 25, filter: ['table' => 'second'], anchor: $first->lastAnchor));
        self::assertSame(['fresh'], self::keys($second->rows));
        self::assertSame(1, $second->totalCount);
        self::assertSame('bigint', $second->rows[0]->sqlType);
        self::assertNull($second->rows[0]->mode);
        self::assertSame(['old'], self::keys($first->rows));

        $table->details['second'] = self::detail('second', [new ChangeLogColumn('fresh', 'varchar', null, null, null)]);
        $third = $table->getPage(new TableQueryDTO(filter: ['table' => 'second']));
        self::assertSame('varchar', $third->rows[0]->sqlType);
        self::assertSame('bigint', $second->rows[0]->sqlType);
        self::assertSame(['first', 'second', 'second'], $table->requests);
    }

    /** @param array<string, mixed> $filter Missing or malformed table preset */
    #[DataProvider('invalidPresets')]
    public function testInvalidTablePresetsAreRefusedBeforeReading(array $filter): void
    {
        $table = new ChangeLogTableColumnsFixtureTable([]);
        $this->expectException(InvalidArgumentException::class);
        try {
            $table->getPage(new TableQueryDTO(filter: $filter));
        } finally {
            self::assertSame([], $table->requests);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid service preset shapes */
    public static function invalidPresets(): iterable
    {
        yield 'missing' => [[]];
        foreach ([null, '', '  ', 1, true, [], ['sample']] as $index => $value) {
            yield 'invalid ' . $index => [['table' => $value]];
        }
    }

    public function testUnknownTableIsRefusedInsteadOfInventingColumns(): void
    {
        $table = new ChangeLogTableColumnsFixtureTable([]);
        $this->expectException(InvalidArgumentException::class);
        try {
            $table->getPage(new TableQueryDTO(filter: ['table' => 'unknown']));
        } finally {
            self::assertSame(['unknown'], $table->requests);
        }
    }

    public function testReadFailurePropagatesInsteadOfBecomingAnEmptyWindow(): void
    {
        $table = new class extends HilosChangeLogTableColumnsTable {
            /**
             * @param string $table Requested table name
             * @return ?ChangeLogTableDetail No metadata on a refused read
             */
            protected function sectionTable(string $table): ?ChangeLogTableDetail
            {
                throw new HilosException('Journal metadata unavailable');
            }
        };
        $this->expectException(HilosException::class);
        $this->expectExceptionMessage('Journal metadata unavailable');
        $table->getPage(new TableQueryDTO(filter: ['table' => 'sample']));
    }

    public function testSearchCannotInjectItsOwnFieldDeclaration(): void
    {
        $table = new ChangeLogTableColumnsFixtureTable([]);
        $this->expectException(TableSearchNotSupportedException::class);
        try {
            $table->getPage(new TableQueryDTO(search: 'id', filter: ['table' => 'sample'], searchableFields: ['name' => 'name']));
        } finally {
            self::assertSame([], $table->requests);
        }
    }

    public function testUndeclaredSingleCompositeAndUnknownSortsKeepSchemaOrderAndWindowAddress(): void
    {
        $table = new ChangeLogTableColumnsFixtureTable(['sample' => self::detail('sample', [
            new ChangeLogColumn('z_first', 'int', null, null, null),
            new ChangeLogColumn('a_last', 'text', null, null, null),
        ])]);
        foreach ([
            TableSortOrderDTO::of(new TableSortDTO('name', 'asc')),
            TableSortOrderDTO::of(new TableSortDTO('sqlType', 'desc'), new TableSortDTO('name', 'desc')),
            TableSortOrderDTO::of(new TableSortDTO('unknown', 'desc')),
        ] as $sort) {
            $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 1, filter: ['table' => 'sample']));
            self::assertSame(['z_first'], self::keys($first->rows));
            self::assertSame(['name' => 'z_first'], $first->lastAnchor?->toArray());
            $next = $table->getPage(new TableQueryDTO(
                sort: $sort, limit: 1, filter: ['table' => 'sample'], anchor: $first->lastAnchor, shownFields: ['name'],
            ));
            self::assertSame(['a_last'], self::keys($next->rows));
            self::assertSame(1, $next->rowsBefore);
            self::assertSame(2, $next->totalCount);
            $jump = $table->getPage(new TableQueryDTO(sort: $sort, limit: 1, filter: ['table' => 'sample'], pageIndex: 1));
            self::assertSame(self::keys($next->rows), self::keys($jump->rows));
        }
    }

    public function testSourceEventsProduceNoMutationsOrReads(): void
    {
        $table = new ChangeLogTableColumnsFixtureTable([]);
        self::assertSame([], $table::BROWSER);
        self::assertFalse(defined($table::class . '::SOURCE_TABLES'));
        self::assertNull($table->facetCounts(new TableQueryDTO(), ['table' => ['sample']]));
        foreach ([
            SourceChange::dbCreated('changeLogTables', '1', []),
            SourceChange::dbUpdated('changeLogTables', '1', []),
            SourceChange::dbDeleted('changeLogTables', '1'),
            SourceChange::dbCleared('changeLogTables'),
            SourceChange::rtUpdated('unrelated', '1', []),
        ] as $change) {
            self::assertNull($table->buildMutationForSourceEvent($change));
        }
        self::assertSame([], $table->requests);
    }

    /**
     * @param list<HilosChangeLogTableColumnsTableRow> $rows Typed viewport rows
     * @return list<string> Column names in window order
     */
    private static function keys(array $rows): array
    {
        return array_map(static fn(HilosChangeLogTableColumnsTableRow $row): string => $row->getRowKey(), $rows);
    }

    /**
     * @param string $name Selected table name
     * @param list<ChangeLogColumn> $columns Live schema order
     * @return ChangeLogTableDetail Request-local table metadata
     */
    private static function detail(string $name, array $columns): ChangeLogTableDetail
    {
        return new ChangeLogTableDetail(
            new ChangeLogTableSummary($name, ChangeLogTableOwner::FRAMEWORK, false, [], null, null), $columns, [],
        );
    }
}

/** Fixture for fresh table-scoped reads through the protected metadata seam. */
final class ChangeLogTableColumnsFixtureTable extends HilosChangeLogTableColumnsTable
{
    /** @var list<string> Table names requested in order */
    public array $requests = [];

    /** @param array<string, ChangeLogTableDetail> $details Fresh table metadata by external catalog name */
    public function __construct(public array $details)
    {
        parent::__construct();
    }

    /**
     * @param string $table Selected table name
     * @return ?ChangeLogTableDetail Fixture metadata, or null for an unknown table
     */
    protected function sectionTable(string $table): ?ChangeLogTableDetail
    {
        $this->requests[] = $table;

        return $this->details[$table] ?? null;
    }
}
