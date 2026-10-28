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
use Hilos\Database\ChangeLog\Section\ChangeLogTableDetail;
use Hilos\Database\ChangeLog\Section\ChangeLogTableOwner;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Database\ChangeLog\Section\ChangeLogTrigger;
use Hilos\HilosException;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTableRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Service trigger windows retain their source order and table-scoped metadata. */
final class HilosChangeLogTableTriggersTableTest extends TestCase
{
    public function testColdPresetComesFromTypedRouteParamsWithoutReadingMetadata(): void
    {
        $table = new ChangeLogTableTriggersFixtureTable([]);
        self::assertSame(['table' => 'sample'], $table->defaultFilter(new PageRouteParams(['tableId' => 'sample'])));
        self::assertSame(['table' => 'other'], $table->defaultFilter(new PageRouteParams(['tableId' => 'other'])));
        self::assertSame([], $table->requests);
    }

    public function testColdPresetRefusesAMissingRouteAddressWithoutReadingMetadata(): void
    {
        $table = new ChangeLogTableTriggersFixtureTable([]);
        $this->expectException(MissingPageRouteParamException::class);
        try {
            $table->defaultFilter(new PageRouteParams([]));
        } finally {
            self::assertSame([], $table->requests);
        }
    }

    public function testProjectionRoundtripEnvelopeAndNotPersonalFields(): void
    {
        $row = HilosChangeLogTableTriggersTableRow::fromTrigger(new ChangeLogTrigger(
            'hilos_cl_sample_after_insert', 'INSERT', 'sample', 42,
        ));
        $expected = [
            'name' => 'hilos_cl_sample_after_insert', 'owner' => 'service', 'event' => 'INSERT',
            'table' => 'sample', 'writesTo' => 'journal', 'validFromMigration' => 42,
        ];
        self::assertSame($expected, $row->toArray());
        self::assertSame($expected, HilosChangeLogTableTriggersTableRow::fromArray($expected)->toArray());
        self::assertSame('hilos_cl_sample_after_insert', $row->getRowKey());
        self::assertSame('name', $row::keyField());

        $table = new HilosChangeLogTableTriggersTable();
        self::assertInstanceOf(ViewportTable::class, $table);
        self::assertSame('hilosChangeLogTableTriggers', $table::TABLE);
        self::assertSame('table', $table::FILTER_TABLE);
        self::assertSame(HilosChangeLogTableTriggersTableRow::class, $table->getRowClass());
        self::assertSame([
            'rowKey' => 'hilos_cl_sample_after_insert', 'sources' => ['trigger' => $expected],
        ], $table->browserRow($row));
        self::assertArrayNotHasKey('id', $row->toArray());
        self::assertArrayNotHasKey('rowKey', $row->toArray());
        self::assertArrayNotHasKey('body', $row->toArray());
        self::assertSame(array_keys($expected), array_keys($table->wireFields()));
        foreach ($table->wireFields() as $field) {
            self::assertEquals(WireField::notPersonal(), $field);
        }
        self::assertSame($expected, ViewerFields::hide(
            $expected, $table->wireFields(), static fn(string $collection, string $field): bool => false,
        ));
    }

    /** @param array<string, mixed> $payload Trigger metadata with one broken field */
    #[DataProvider('malformedRows')]
    public function testMalformedRowFieldsAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosChangeLogTableTriggersTableRow::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Required strings and strict integer migration */
    public static function malformedRows(): iterable
    {
        $valid = [
            'name' => 'hilos_cl_sample_after_insert', 'owner' => 'service', 'event' => 'INSERT',
            'table' => 'sample', 'writesTo' => 'journal', 'validFromMigration' => 42,
        ];
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield $field . ' missing' => [$missing];
            yield $field . ' null' => [array_replace($valid, [$field => null])];
            yield $field . ' boolean' => [array_replace($valid, [$field => false])];
            yield $field . ' array' => [array_replace($valid, [$field => []])];
            if ($field !== 'validFromMigration') {
                yield $field . ' integer' => [array_replace($valid, [$field => 1])];
            }
        }
        yield 'migration string' => [array_replace($valid, ['validFromMigration' => '42'])];
        yield 'migration float' => [array_replace($valid, ['validFromMigration' => 42.0])];
    }

    public function testOpeningWindowKeepsInsertUpdateDeleteOrderAndIntegerMigrations(): void
    {
        $table = new ChangeLogTableTriggersFixtureTable(['sample' => self::detail('sample')]);
        self::assertSame(25, $table->windowSize());
        self::assertNull($table->defaultSort());
        $snapshot = $table->getPage(new TableQueryDTO(limit: $table->windowSize(), filter: ['table' => 'sample']));
        self::assertSame([
            'hilos_cl_sample_after_insert', 'hilos_cl_sample_after_update', 'hilos_cl_sample_after_delete',
        ], self::keys($snapshot->rows));
        self::assertSame(['INSERT', 'UPDATE', 'DELETE'],
            array_map(static fn(HilosChangeLogTableTriggersTableRow $row): string => $row->event, $snapshot->rows));
        self::assertSame([42, 43, 44],
            array_map(static fn(HilosChangeLogTableTriggersTableRow $row): int => $row->validFromMigration, $snapshot->rows));
        self::assertSame(3, $snapshot->totalCount);
        self::assertTrue($snapshot->totalExact);
        self::assertSame(25, $snapshot->limit);
        self::assertSame(0, $snapshot->rowsBefore);
        self::assertNull($snapshot->frame?->before);
        self::assertNull($snapshot->frame?->after);
        foreach ($snapshot->rows as $row) {
            self::assertSame('service', $row->owner);
            self::assertSame('journal', $row->writesTo);
            self::assertSame('sample', $row->table);
        }
    }

    public function testWindowsAnchorsInBothDirectionsAndPageJumps(): void
    {
        $table = new ChangeLogTableTriggersFixtureTable(['sample' => self::detail('sample')]);
        $filter = ['table' => 'sample'];
        $first = $table->getPage(new TableQueryDTO(limit: 2, filter: $filter));
        self::assertCount(2, $first->rows);
        self::assertSame(3, $first->totalCount);
        self::assertTrue($first->totalExact);
        self::assertSame(0, $first->rowsBefore);
        self::assertSame(['name' => 'hilos_cl_sample_after_insert'], $first->firstAnchor?->toArray());
        self::assertSame(['name' => 'hilos_cl_sample_after_update'], $first->lastAnchor?->toArray());
        self::assertNull($first->frame?->before);
        self::assertSame(['name' => 'hilos_cl_sample_after_delete'], $first->frame?->after?->toArray());
        $next = $table->getPage(new TableQueryDTO(limit: 2, filter: $filter, anchor: $first->lastAnchor));
        self::assertSame(['hilos_cl_sample_after_delete'], self::keys($next->rows));
        self::assertSame(2, $next->rowsBefore);
        self::assertSame(3, $next->totalCount);
        self::assertTrue($next->totalExact);
        self::assertSame(['name' => 'hilos_cl_sample_after_delete'], $next->firstAnchor?->toArray());
        self::assertSame($next->firstAnchor?->toArray(), $next->lastAnchor?->toArray());
        self::assertSame($first->lastAnchor?->toArray(), $next->frame?->before?->toArray());
        self::assertNull($next->frame?->after);
        $previous = $table->getPage(new TableQueryDTO(
            limit: 2, filter: $filter, anchor: $next->firstAnchor, anchorDirection: TableAnchorDirection::Before,
        ));
        self::assertSame(self::keys($first->rows), self::keys($previous->rows));
        self::assertSame(0, $previous->rowsBefore);
        $numbered = $table->getPage(new TableQueryDTO(limit: 2, filter: $filter, pageIndex: 1));
        self::assertSame(self::keys($next->rows), self::keys($numbered->rows));
        self::assertSame(2, $numbered->rowsBefore);
        $afterLast = $table->getPage(new TableQueryDTO(limit: 2, filter: $filter, anchor: $next->lastAnchor));
        self::assertSame([], $afterLast->rows);
        self::assertSame(3, $afterLast->totalCount);
        self::assertSame(3, $afterLast->rowsBefore);
        self::assertTrue($afterLast->totalExact);
        self::assertNull($afterLast->firstAnchor);
        self::assertNull($afterLast->lastAnchor);
        self::assertNull($afterLast->frame);
        $beyond = $table->getPage(new TableQueryDTO(limit: 2, filter: $filter, pageIndex: 2));
        self::assertSame([], $beyond->rows);
        self::assertSame(4, $beyond->rowsBefore);
        self::assertSame(3, $beyond->totalCount);
    }

    public function testUntrackedTableHasAnExactEmptyTriggerWindow(): void
    {
        $table = new ChangeLogTableTriggersFixtureTable(['untracked' => self::detail('untracked', false)]);
        $snapshot = $table->getPage(new TableQueryDTO(limit: 25, filter: ['table' => 'untracked']));
        self::assertSame([], $snapshot->rows);
        self::assertSame(0, $snapshot->totalCount);
        self::assertTrue($snapshot->totalExact);
        self::assertSame(25, $snapshot->limit);
        self::assertSame(0, $snapshot->rowsBefore);
        self::assertNull($snapshot->firstAnchor);
        self::assertNull($snapshot->lastAnchor);
        self::assertNull($snapshot->frame);
    }

    public function testChangingTheTablePresetAndReusingAnOldAnchorNeverReturnsOldTriggers(): void
    {
        $table = new ChangeLogTableTriggersFixtureTable([
            'first' => self::detail('first'), 'second' => self::detail('second'),
        ]);
        $first = $table->getPage(new TableQueryDTO(limit: 25, filter: ['table' => 'first']));
        $second = $table->getPage(new TableQueryDTO(limit: 25, filter: ['table' => 'second'], anchor: $first->lastAnchor));
        self::assertSame([
            'hilos_cl_second_after_insert', 'hilos_cl_second_after_update', 'hilos_cl_second_after_delete',
        ], self::keys($second->rows));
        self::assertSame(3, $second->totalCount);
        foreach ($second->rows as $row) {
            self::assertSame('second', $row->table);
        }
        self::assertSame('first', $first->rows[0]->table);
        $table->details['second'] = self::detail('second', false);
        $third = $table->getPage(new TableQueryDTO(limit: 25, filter: ['table' => 'second'], anchor: $second->lastAnchor));
        self::assertSame([], $third->rows);
        self::assertSame(0, $third->totalCount);
        self::assertCount(3, $second->rows);
        self::assertSame(['first', 'second', 'second'], $table->requests);
    }

    /** @param array<string, mixed> $filter Missing or malformed table preset */
    #[DataProvider('invalidPresets')]
    public function testInvalidTablePresetsAreRefusedBeforeReading(array $filter): void
    {
        $table = new ChangeLogTableTriggersFixtureTable([]);
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

    public function testUnknownTableIsRefusedInsteadOfInventingTriggers(): void
    {
        $table = new ChangeLogTableTriggersFixtureTable([]);
        $this->expectException(InvalidArgumentException::class);
        try {
            $table->getPage(new TableQueryDTO(filter: ['table' => 'unknown']));
        } finally {
            self::assertSame(['unknown'], $table->requests);
        }
    }

    public function testReadFailurePropagatesInsteadOfBecomingAnEmptyWindow(): void
    {
        $table = new class extends HilosChangeLogTableTriggersTable {
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
        $table = new ChangeLogTableTriggersFixtureTable([]);
        $this->expectException(TableSearchNotSupportedException::class);
        try {
            $table->getPage(new TableQueryDTO(
                search: 'INSERT', filter: ['table' => 'sample'], searchableFields: ['event' => 'event'],
            ));
        } finally {
            self::assertSame([], $table->requests);
        }
    }

    public function testUndeclaredSingleCompositeAndUnknownSortsKeepEventOrderAndWindowAddress(): void
    {
        $table = new ChangeLogTableTriggersFixtureTable(['sample' => self::detail('sample')]);
        foreach ([
            TableSortOrderDTO::of(new TableSortDTO('event', 'asc')),
            TableSortOrderDTO::of(new TableSortDTO('event', 'desc'), new TableSortDTO('name', 'desc')),
            TableSortOrderDTO::of(new TableSortDTO('unknown', 'desc')),
        ] as $sort) {
            $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, filter: ['table' => 'sample']));
            self::assertSame(['hilos_cl_sample_after_insert', 'hilos_cl_sample_after_update'], self::keys($first->rows));
            self::assertSame(['name' => 'hilos_cl_sample_after_update'], $first->lastAnchor?->toArray());
            $next = $table->getPage(new TableQueryDTO(
                sort: $sort, limit: 2, filter: ['table' => 'sample'], anchor: $first->lastAnchor, shownFields: ['name'],
            ));
            self::assertSame(['hilos_cl_sample_after_delete'], self::keys($next->rows));
            self::assertSame(2, $next->rowsBefore);
            self::assertSame(3, $next->totalCount);
            $jump = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, filter: ['table' => 'sample'], pageIndex: 1));
            self::assertSame(self::keys($next->rows), self::keys($jump->rows));
        }
    }

    public function testSourceEventsProduceNoMutationsOrReads(): void
    {
        $table = new ChangeLogTableTriggersFixtureTable([]);
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
     * @param list<HilosChangeLogTableTriggersTableRow> $rows Typed viewport rows
     * @return list<string> Trigger names in window order
     */
    private static function keys(array $rows): array
    {
        return array_map(static fn(HilosChangeLogTableTriggersTableRow $row): string => $row->getRowKey(), $rows);
    }

    /**
     * @param string $name Selected table name
     * @param bool $journaled Whether service triggers exist for this table
     * @return ChangeLogTableDetail Request-local metadata in INSERT, UPDATE, DELETE order
     */
    private static function detail(string $name, bool $journaled = true): ChangeLogTableDetail
    {
        return new ChangeLogTableDetail(
            new ChangeLogTableSummary($name, ChangeLogTableOwner::FRAMEWORK, $journaled, [], null, null),
            [],
            $journaled ? [
                new ChangeLogTrigger('hilos_cl_' . $name . '_after_insert', 'INSERT', $name, 42),
                new ChangeLogTrigger('hilos_cl_' . $name . '_after_update', 'UPDATE', $name, 43),
                new ChangeLogTrigger('hilos_cl_' . $name . '_after_delete', 'DELETE', $name, 44),
            ] : [],
        );
    }
}

/** Fixture for fresh table-scoped reads through the protected metadata seam. */
final class ChangeLogTableTriggersFixtureTable extends HilosChangeLogTableTriggersTable
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
