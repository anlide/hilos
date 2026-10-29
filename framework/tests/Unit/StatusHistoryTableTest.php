<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Tables\Status\HilosStatusHistoryTable;
use Hilos\Tables\Status\HilosStatusHistoryTableRow;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\DatabaseException;
use Hilos\Status\DTO\StatusHistoryAnchor;
use Hilos\Status\DTO\StatusHistoryEntry;
use Hilos\Status\DTO\StatusHistoryPortion;
use Hilos\Status\DTO\StatusHistoryQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact history projection, numeric position and read-only table declarations. */
final class StatusHistoryTableTest extends TestCase
{
    /** Keeps the two namespaces distinct and exposes only the approved historical fields. */
    public function testCompletedProjectionsRetainSnapshotsAndUnknownExactEnd(): void
    {
        $incident = StatusHistoryEntry::fromIncidentRow(self::incidentRow());
        self::assertSame([
            'id' => 'incident:2', 'kind' => 'incident', 'sourceId' => 2, 'startedAt' => 1000,
            'endedAt' => 3000, 'completionObservedAt' => null, 'publishedAt' => 2000, 'outcome' => 'resolved',
            'title' => 'Retired component', 'componentKeys' => ['retired'], 'severity' => 'major_outage',
            'announcementKnown' => null, 'workPublicId' => null,
        ], $incident->toArray());
        self::assertSame(['startedAt' => 1000, 'kindRank' => 0, 'sourceId' => 2], $incident->anchor()->toArray());
        $maintenance = StatusHistoryEntry::fromMaintenanceRow([
            'id' => 2, 'started_at' => 1000, 'ended_at' => null, 'completion_observed_at' => 5000,
            'title' => 'Original announcement', 'component_keys_json' => '["retired","api"]',
            'announcement_known' => 1, 'work_public_id' => 'saved-public-id', 'work_id' => 900,
            'operation' => 'private fixture field',
        ]);
        self::assertSame([
            'id' => 'maintenance:2', 'kind' => 'maintenance', 'sourceId' => 2, 'startedAt' => 1000,
            'endedAt' => null, 'completionObservedAt' => 5000, 'publishedAt' => null, 'outcome' => 'maintenance_completed',
            'title' => 'Original announcement', 'componentKeys' => ['retired', 'api'], 'severity' => null,
            'announcementKnown' => true, 'workPublicId' => 'saved-public-id',
        ], $maintenance->toArray());
        self::assertSame(['startedAt' => 1000, 'kindRank' => 1, 'sourceId' => 2], $maintenance->anchor()->toArray());
    }

    /** Unpublished completion uses the saved current severity, even if a peak field is present. */
    public function testUnpublishedProjectionDoesNotBorrowPublicationSeverity(): void
    {
        $row = self::incidentRow();
        $row['published_at'] = null;
        $row['outcome'] = 'recovered_before_publication';
        self::assertSame('partial_outage', StatusHistoryEntry::fromIncidentRow($row)->severity);
        $row['published_at'] = 2000;
        $row['peak_severity'] = null;
        self::assertSame('partial_outage', StatusHistoryEntry::fromIncidentRow($row)->severity);
    }

    /** A corrupt persisted completion never becomes a fabricated history row. */
    public function testMissingIncidentOutcomeIsAStorageFailure(): void
    {
        $row = self::incidentRow();
        $row['outcome'] = null;
        $this->expectException(DatabaseException::class);
        StatusHistoryEntry::fromIncidentRow($row);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid storage-window requests */
    public static function invalidQueries(): iterable
    {
        yield 'empty component' => [['componentKey' => ' ']];
        yield 'unknown outcome' => [['outcome' => 'active']];
        yield 'unknown order' => [['order' => 'sideways']];
        yield 'zero limit' => [['limit' => 0]];
        yield 'large limit' => [['limit' => 33]];
        yield 'negative skip' => [['edgeSkip' => -1]];
        yield 'large skip' => [['edgeSkip' => 501]];
        yield 'equal bounds' => [['fromMs' => 2000, 'toExclusiveMs' => 2000]];
        yield 'reversed bounds' => [['fromMs' => 3000, 'toExclusiveMs' => 2000]];
        yield 'skip with anchor' => [['anchor' => new StatusHistoryAnchor(1000, 0, 2), 'edgeSkip' => 1]];
    }

    /** @param array<string, mixed> $arguments Invalid constructor input */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueryIsRefused(array $arguments): void
    {
        $this->expectException(ValidationException::class);
        new StatusHistoryQuery(...$arguments);
    }

    /** The prefix budget differs from an ordinary window, and Before reverses the SQL walk. */
    public function testSourceBudgetsAndCanonicalDirections(): void
    {
        self::assertSame(33, new StatusHistoryQuery()->sourceLimit());
        self::assertSame(533, new StatusHistoryQuery(edgeSkip: 500)->sourceLimit());
        self::assertSame(34, new StatusHistoryQuery(limit: 1, edgeSkip: 32)->sourceLimit());
        self::assertNull(new StatusHistoryQuery(term: '  ')->term);
        self::assertSame('50%_!', new StatusHistoryQuery(term: ' 50%_! ')->term);
        self::assertFalse(new StatusHistoryQuery()->readsAscending());
        self::assertTrue(new StatusHistoryQuery(order: 'asc')->readsAscending());
        self::assertTrue(new StatusHistoryQuery(direction: TableAnchorDirection::Before)->readsAscending());
        self::assertFalse(new StatusHistoryQuery(order: 'asc', direction: TableAnchorDirection::Before)->readsAscending());
    }

    /** @return iterable<string, array{int, int}> Invalid source identities */
    public static function invalidAnchors(): iterable
    {
        yield 'negative kind' => [-1, 2];
        yield 'unknown kind' => [2, 2];
        yield 'zero id' => [0, 0];
        yield 'negative id' => [1, -2];
    }

    /**
     * @param int $rank Invalid rank or rank paired with an invalid id
     * @param int $id Source id
     */
    #[DataProvider('invalidAnchors')]
    public function testIncompleteAnchorIsRefused(int $rank, int $id): void
    {
        $this->expectException(ValidationException::class);
        new StatusHistoryAnchor(1000, $rank, $id);
    }

    /** The typed portion cannot be used as an unbounded row cache. */
    public function testOversizedPortionIsRefused(): void
    {
        $entry = StatusHistoryEntry::fromIncidentRow(self::incidentRow());
        $this->expectException(ValidationException::class);
        new StatusHistoryPortion(array_fill(0, 533, $entry), true);
    }

    /** Both storage titles share the one normalized browser field; private source fields stay out. */
    public function testReadOnlyTableEnvelopeAndDeclarations(): void
    {
        $table = new HilosStatusHistoryTable();
        $entry = StatusHistoryEntry::fromIncidentRow(self::incidentRow());
        $row = HilosStatusHistoryTableRow::fromEntry($entry);
        self::assertSame($entry->toArray(), $row->toArray());
        self::assertSame($row->toArray(), HilosStatusHistoryTableRow::fromArray($row->toArray() + ['actorId' => 9])->toArray());
        self::assertSame(['rowKey' => 'incident:2', 'sources' => ['history' => $entry->toArray()]], $table->browserRow($row));
        self::assertSame('id', HilosStatusHistoryTableRow::keyField());
        self::assertSame(32, $table->windowSize());
        self::assertSame([['field' => 'startedAt', 'direction' => 'desc']], $table->defaultSort()->toArray());
        self::assertSame([], $table->bulkActions());
        self::assertNull($table->getItemActionsClass());
        self::assertSame(array_keys($entry->toArray()), array_keys($table->wireFields()));
        self::assertSame(['title' => 'title'], $table->scopeSearch(new TableQueryDTO(search: 'saved'))->searchableFields);
        self::assertNull($table->scopeSearch(new TableQueryDTO(search: 'saved', shownFields: ['outcome']))->search);
        self::assertSame(['statusIncidents', 'statusMaintenancePeriods'],
            array_column(HilosStatusHistoryTable::BROWSER[BrowserTableConfigKey::SOURCES], BrowserSourceKey::KEY));
        $sources = HilosStatusHistoryTable::BROWSER[BrowserTableConfigKey::ROWS];
        self::assertCount(2, $sources);
        self::assertSame(['statusIncidents', 'statusMaintenancePeriods'], array_map(
            static fn(array $source): string => $source[BrowserTableFieldKey::SOURCE][BrowserSourceKey::KEY], $sources));
        self::assertSame('id', $sources[0][BrowserTableFieldKey::ROW_KEY]);
        self::assertSame('id', $sources[1][BrowserTableFieldKey::ROW_KEY]);
    }

    /** Equal moments and numeric source ids use the same tuple for focus and window placement. */
    public function testNumericPlacementNeverComparesNamespaceStrings(): void
    {
        $table = new HilosStatusHistoryTable();
        $wire = StatusHistoryEntry::fromIncidentRow(self::incidentRow())->toArray();
        $ten = HilosStatusHistoryTableRow::fromArray(array_replace($wire, ['id' => 'incident:10', 'sourceId' => 10]));
        $anchor = new TableAnchorDTO(['startedAt' => 1000, 'kindRank' => 0, 'sourceId' => 2]);
        self::assertSame(['startedAt' => 1000, 'kindRank' => 0, 'sourceId' => 10],
            $table->anchorForRow($ten, new TableQueryDTO())->values);
        self::assertSame(-1, $table->placeRowAgainst($ten, $anchor, new TableQueryDTO()));
        self::assertSame(1, $table->placeRowAgainst($ten, $anchor,
            new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO('startedAt', 'asc')))));
        self::assertSame(1, $table->placeRowAgainst($ten,
            new TableAnchorDTO(['startedAt' => 1000, 'kindRank' => 1, 'sourceId' => 2]), new TableQueryDTO()));
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed browser-returned tuples */
    public static function malformedTableAnchors(): iterable
    {
        yield 'missing rank' => [['startedAt' => 1000, 'sourceId' => 2]];
        yield 'string time' => [['startedAt' => '1000', 'kindRank' => 0, 'sourceId' => 2]];
        yield 'string id' => [['startedAt' => 1000, 'kindRank' => 0, 'sourceId' => '2']];
        yield 'invalid rank' => [['startedAt' => 1000, 'kindRank' => 2, 'sourceId' => 2]];
        yield 'zero id' => [['startedAt' => 1000, 'kindRank' => 0, 'sourceId' => 0]];
        yield 'extra field' => [['startedAt' => 1000, 'kindRank' => 0, 'sourceId' => 2, 'id' => 'incident:2']];
    }

    /** @param array<string, mixed> $values Malformed anchor values */
    #[DataProvider('malformedTableAnchors')]
    public function testMalformedTableAnchorIsRefused(array $values): void
    {
        $row = HilosStatusHistoryTableRow::fromEntry(StatusHistoryEntry::fromIncidentRow(self::incidentRow()));
        self::assertNull(new HilosStatusHistoryTable()->placeRowAgainst($row, new TableAnchorDTO($values), new TableQueryDTO()));
        $this->expectException(ValidationException::class);
        new HilosStatusHistoryTable()->getPage(new TableQueryDTO(anchor: new TableAnchorDTO($values)));
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid calendar, zone and scalar filters */
    public static function invalidTableFilters(): iterable
    {
        yield 'impossible date' => [['from' => '2026-02-29']];
        yield 'unpadded date' => [['to' => '2026-2-03']];
        yield 'date with time' => [['to' => '2026-02-03T00:00:00Z']];
        yield 'date with spaces' => [['from' => ' 2026-02-03']];
        yield 'numeric date' => [['from' => 20260203]];
        yield 'reversed range' => [['from' => '2026-03-30', 'to' => '2026-03-29']];
        yield 'offset zone' => [['timeZone' => '+02:00']];
        yield 'unknown zone' => [['timeZone' => 'Mars/Olympus']];
        yield 'null zone' => [['timeZone' => null]];
        yield 'blank zone' => [['timeZone' => '']];
        yield 'numeric zone' => [['timeZone' => 1]];
        yield 'blank component' => [['componentKey' => ' ']];
        yield 'numeric component' => [['componentKey' => 1]];
        yield 'unknown outcome' => [['outcome' => 'running']];
        yield 'null outcome' => [['outcome' => null]];
    }

    /** @param array<string, mixed> $filter Malformed browser filter map */
    #[DataProvider('invalidTableFilters')]
    public function testMalformedHistoryFilterIsRefusedBeforeReadingStorage(array $filter): void
    {
        $this->expectException(ValidationException::class);
        new HilosStatusHistoryTable()->getPage(new TableQueryDTO(filter: $filter));
    }

    /** Every nullable field is still required by the exact projection contract. */
    public function testMissingNullableHistoryFieldIsRefused(): void
    {
        $wire = StatusHistoryEntry::fromIncidentRow(self::incidentRow())->toArray();
        unset($wire['endedAt']);
        $this->expectException(InvalidFormatException::class);
        HilosStatusHistoryTableRow::fromArray($wire);
    }

    /** Source ids are scoped before projection; an unrelated or RT source has no history identity. */
    public function testSourceAddressingAndDeleteStayInTheirNamespace(): void
    {
        $table = new HilosStatusHistoryTable();
        foreach (['statusIncidents' => 'incident:2', 'statusMaintenancePeriods' => 'maintenance:2'] as $source => $key) {
            $change = SourceChange::dbDeleted($source, '2');
            self::assertSame($key, $table->rowKeyForSourceEvent($change));
            self::assertSame($key, $table->buildMutationForSourceEvent($change)->rowKey);
            self::assertSame('delete', $table->buildMutationForSourceEvent($change)->type->value);
        }
        self::assertNull($table->rowKeyForSourceEvent(SourceChange::dbUpdated('statusIncidents', '02', [])));
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::rtUpdated('statusIncidents', '2', [])));
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbUpdated('statusWorks', '2', [])));
    }

    /** Clearing either half makes the whole window owed rather than leaving a successful half. */
    public function testClearRequiresACompleteRebuild(): void
    {
        $this->expectException(PageInternalErrorException::class);
        new HilosStatusHistoryTable()->buildMutationForSourceEvent(SourceChange::dbCleared(HilosDbContext::statusMaintenancePeriods));
    }

    /** @return array<string, mixed> Raw completed incident boundary, including a field outside the browser contract */
    private static function incidentRow(): array
    {
        return [
            'id' => 2, 'component_key' => 'retired', 'component_name' => 'Retired component',
            'severity' => 'partial_outage', 'peak_severity' => 'major_outage', 'detected_at' => 1000,
            'resolved_at' => 3000, 'published_at' => 2000, 'outcome' => 'resolved', 'suppressed_at' => null,
        ];
    }
}
