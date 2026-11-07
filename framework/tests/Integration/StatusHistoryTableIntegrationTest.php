<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use DateTimeImmutable;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Tables\Status\HilosStatusHistoryTable;
use Hilos\Tables\Status\HilosStatusHistoryTableRow;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Hilos;
use Hilos\Status\DTO\StatusHistoryAnchor;
use Hilos\Status\DTO\StatusHistoryEntry;
use Hilos\Status\DTO\StatusHistoryQuery;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real storage predicates and merged viewport reads without production page activation. */
#[IntegrationTest]
final class StatusHistoryTableIntegrationTest extends StatusHistoryStorageTestCase
{
    /** Completion is independent of publication, exact end, current catalog and current announcement rows. */
    public function testCompletedFactsKeepTheirSavedProjectionAndExcludeOpenCandidates(): void
    {
        $this->incident(2, 1000);
        $this->incident(10, 1000, 'recovered_before_publication', null);
        $this->incident(11, 1000, 'canceled_publication', null);
        $this->incident(12, 1000, null, null);
        $this->incident(13, 1000, null, 1500);
        $this->period(2, 1000, true, null);
        $this->period(10, 1000, true, 3000);
        $this->period(11, 1000, false);
        Database::sql("UPDATE `hilos_status_incident` SET `component_key` = 'open-only' WHERE `resolved_at` IS NULL");
        Database::sql("UPDATE `hilos_status_maintenance_period` SET `component_keys_json` = '[\"open-only\"]'"
            . ' WHERE `completion_observed_at` IS NULL');

        $incidents = Hilos::$db->statusIncidents->historyPortion(new StatusHistoryQuery())->entries;
        self::assertSame(['incident:11', 'incident:10', 'incident:2'], array_column($incidents, 'id'));
        self::assertSame(['canceled_publication', 'recovered_before_publication', 'resolved'], array_column($incidents, 'outcome'));
        self::assertSame(['partial_outage', 'partial_outage', 'major_outage'], array_column($incidents, 'severity'));
        self::assertSame('Saved 50%_! component', $incidents[0]->title);
        self::assertSame(['retired'], $incidents[0]->componentKeys);
        $periods = Hilos::$db->statusMaintenancePeriods->historyPortion(new StatusHistoryQuery())->entries;
        self::assertSame(['maintenance:10', 'maintenance:2'], array_column($periods, 'id'));
        self::assertSame(3000, $periods[0]->endedAt);
        self::assertNull($periods[1]->endedAt);
        self::assertSame(3000, $periods[1]->completionObservedAt);
        self::assertSame('Saved 50%_! work', $periods[1]->title);
        self::assertSame(str_repeat('b', 32), $periods[1]->workPublicId);
        self::assertTrue($periods[1]->announcementKnown);
        self::assertSame(['retired', 'api'], $periods[1]->componentKeys);
        self::assertSame(3, Hilos::$db->statusIncidents->completedCount());
        self::assertSame(2, Hilos::$db->statusMaintenancePeriods->completedCount());
        self::assertSame(['retired'], Hilos::$db->statusIncidents->historyComponentKeys());
        self::assertSame(['api', 'retired'], Hilos::$db->statusMaintenancePeriods->historyComponentKeys());
        self::assertFalse(Hilos::$db->statusIncidents->historyPortion(new StatusHistoryQuery())->hasMore);
    }

    /** @return iterable<string, array{string, TableAnchorDirection, int, int, list<int>, list<int>}> Full tuple sides */
    public static function tupleSides(): iterable
    {
        $cases = [
            'incident 2' => [0, 2, [10, 11], [2, 10, 11], [1], [1]],
            'incident 10' => [0, 10, [11], [2, 10, 11], [1, 2], [1]],
            'maintenance 2' => [1, 2, [11], [10, 11], [1, 2, 10], [1]],
            'maintenance 10' => [1, 10, [11], [11], [1, 2, 10], [1, 2]],
        ];
        foreach ($cases as $name => [$rank, $id, $afterI, $afterM, $beforeI, $beforeM]) {
            yield $name . ' asc after' => ['asc', TableAnchorDirection::After, $rank, $id, $afterI, $afterM];
            yield $name . ' asc before' => ['asc', TableAnchorDirection::Before, $rank, $id, $beforeI, $beforeM];
            yield $name . ' desc after' => ['desc', TableAnchorDirection::After, $rank, $id,
                array_reverse($beforeI), array_reverse($beforeM)];
            yield $name . ' desc before' => ['desc', TableAnchorDirection::Before, $rank, $id,
                array_reverse($afterI), array_reverse($afterM)];
        }
    }

    /**
     * @param string $order Canonical tuple order
     * @param TableAnchorDirection $direction Requested side
     * @param int $rank Anchor namespace
     * @param int $id Anchor numeric key
     * @param list<int> $incidentIds Expected incident keys
     * @param list<int> $periodIds Expected maintenance keys
     */
    #[DataProvider('tupleSides')]
    public function testBothSourcesHonorGlobalTupleSidesAndNumericIds(
        string $order,
        TableAnchorDirection $direction,
        int $rank,
        int $id,
        array $incidentIds,
        array $periodIds,
    ): void {
        foreach ([1 => 900, 2 => 1000, 10 => 1000, 11 => 1100] as $sourceId => $at) {
            $this->incident($sourceId, $at);
            $this->period($sourceId, $at);
        }
        $query = new StatusHistoryQuery(order: $order, anchor: new StatusHistoryAnchor(1000, $rank, $id), direction: $direction);
        self::assertSame($incidentIds, array_column(Hilos::$db->statusIncidents->historyPortion($query)->entries, 'sourceId'));
        self::assertSame($periodIds, array_column(Hilos::$db->statusMaintenancePeriods->historyPortion($query)->entries, 'sourceId'));
        self::assertSame(count($incidentIds), Hilos::$db->statusIncidents->historyCount($query)->count);
        self::assertSame(count($periodIds), Hilos::$db->statusMaintenancePeriods->historyCount($query)->count);
        self::assertTrue(Hilos::$db->statusIncidents->historyCount($query)->exact);
        self::assertTrue(Hilos::$db->statusMaintenancePeriods->historyCount($query)->exact);
    }

    /** Literal wildcards, JSON element membership, outcomes and half-open start bounds share the count predicate. */
    public function testFiltersAndCountsUseTheSameCompletedSet(): void
    {
        foreach ([1 => 900, 2 => 1000, 3 => 2000] as $id => $at) {
            $this->incident($id, $at);
            $this->period($id, $at);
        }
        $this->incident(4, 1000, null, null);
        $this->period(4, 1000, false);
        $this->incident(5, 1000, title: 'Saved 50xx component');
        $this->period(5, 1000, keys: '["retired-api"]', title: 'Saved 50xx work');
        $query = new StatusHistoryQuery(term: '50%_!', componentKey: 'retired', fromMs: 1000, toExclusiveMs: 2000);
        foreach ([Hilos::$db->statusIncidents, Hilos::$db->statusMaintenancePeriods] as $source) {
            self::assertSame([2], array_column($source->historyPortion($query)->entries, 'sourceId'));
            self::assertSame(['count' => 1, 'exact' => true], $source->historyCount($query)->toArray());
        }
        $maintenanceOnly = new StatusHistoryQuery(outcome: 'maintenance_completed', componentKey: 'retired');
        self::assertSame([], Hilos::$db->statusIncidents->historyPortion($maintenanceOnly)->entries);
        self::assertSame(0, Hilos::$db->statusIncidents->historyCount($maintenanceOnly)->count);
        self::assertSame(3, Hilos::$db->statusMaintenancePeriods->historyCount($maintenanceOnly)->count);
        $incidentOnly = new StatusHistoryQuery(outcome: 'resolved');
        self::assertSame([], Hilos::$db->statusMaintenancePeriods->historyPortion($incidentOnly)->entries);
        self::assertSame(0, Hilos::$db->statusMaintenancePeriods->historyCount($incidentOnly)->count);
        self::assertSame(4, Hilos::$db->statusIncidents->completedCount());
        self::assertSame(4, Hilos::$db->statusMaintenancePeriods->completedCount());
    }

    /** Each numbered source must provide its full bounded prefix, including entries beyond its first 32. */
    public function testNumberedPrefixesCountCeilingAndDetachedSourceMaps(): void
    {
        for ($id = 1; $id <= 540; ++$id) {
            $this->incident($id, $id * 2);
            $this->period($id, $id * 2 + 1);
        }
        foreach ([Hilos::$db->statusIncidents, Hilos::$db->statusMaintenancePeriods] as $source) {
            $first = $source->historyPortion(new StatusHistoryQuery());
            self::assertCount(32, $first->entries);
            self::assertTrue($first->hasMore);
            $prefix = $source->historyPortion(new StatusHistoryQuery(edgeSkip: 64));
            self::assertSame(range(540, 445), array_column($prefix->entries, 'sourceId'));
            self::assertTrue($prefix->hasMore);
            $lastPrefix = $source->historyPortion(new StatusHistoryQuery(edgeSkip: 500));
            self::assertCount(532, $lastPrefix->entries);
            self::assertTrue($lastPrefix->hasMore);
            self::assertSame(['count' => 500, 'exact' => false], $source->historyCount(new StatusHistoryQuery())->toArray());
            self::assertSame(['count' => 500, 'exact' => true], $source->historyCount(new StatusHistoryQuery(fromMs: 82))->toArray());
            self::assertSame(540, $source->completedCount());
            $exactWindow = $source->historyPortion(new StatusHistoryQuery(fromMs: 1018));
            self::assertCount(32, $exactWindow->entries);
            self::assertFalse($exactWindow->hasMore);
            self::assertTrue($source->historyPortion(new StatusHistoryQuery(fromMs: 1016))->hasMore);
            $ascending = $source->historyPortion(new StatusHistoryQuery(order: 'asc', edgeSkip: 64));
            self::assertSame(range(1, 96), array_column($ascending->entries, 'sourceId'));
            $beforePrefix = $source->historyPortion(new StatusHistoryQuery(direction: TableAnchorDirection::Before, edgeSkip: 64));
            self::assertSame(range(96, 1), array_column($beforePrefix->entries, 'sourceId'));
            self::assertSame([], new ReflectionProperty(Objects::class, 'objects')->getValue($source->getObjectCollection()));
        }
        $prefix = array_merge(
            Hilos::$db->statusIncidents->historyPortion(new StatusHistoryQuery(edgeSkip: 64))->entries,
            Hilos::$db->statusMaintenancePeriods->historyPortion(new StatusHistoryQuery(edgeSkip: 64))->entries,
        );
        usort($prefix, static fn(StatusHistoryEntry $a, StatusHistoryEntry $b): int => $b->startedAt <=> $a->startedAt);
        self::assertSame('maintenance:508', $prefix[64]->id);
        self::assertSame('incident:493', $prefix[95]->id);
        $last = Hilos::$db->statusIncidents->historyPortion(new StatusHistoryQuery(direction: TableAnchorDirection::Before));
        self::assertSame(range(32, 1), array_column($last->entries, 'sourceId'));
        self::assertTrue($last->hasMore);
    }

    /** Consecutive source windows have no duplicates or holes and Before returns the preceding canonical window. */
    public function testMultiPageKeysetsInBothOrders(): void
    {
        for ($id = 1; $id <= 70; ++$id) {
            $this->incident($id, 1000);
            $this->period($id, 1000);
        }
        foreach ([Hilos::$db->statusIncidents, Hilos::$db->statusMaintenancePeriods] as $source) {
            foreach (['asc', 'desc'] as $order) {
                $first = $source->historyPortion(new StatusHistoryQuery(order: $order));
                $second = $source->historyPortion(new StatusHistoryQuery(order: $order, anchor: $first->entries[31]->anchor()));
                $third = $source->historyPortion(new StatusHistoryQuery(order: $order, anchor: $second->entries[31]->anchor()));
                self::assertSame($order === 'asc' ? range(1, 70) : range(70, 1),
                    array_column(array_merge($first->entries, $second->entries, $third->entries), 'sourceId'));
                self::assertFalse($third->hasMore);
                $previous = $source->historyPortion(new StatusHistoryQuery(order: $order,
                    anchor: $second->entries[0]->anchor(), direction: TableAnchorDirection::Before));
                self::assertSame(array_column($first->entries, 'id'), array_column($previous->entries, 'id'));
            }
        }
    }

    /** A deleted tuple remains a navigation boundary, without loading the vanished row. */
    public function testDeletedAnchorStillSelectsItsStrictSide(): void
    {
        $this->incident(2, 1000);
        $this->incident(10, 1000);
        $this->period(2, 1000);
        Database::sql('DELETE FROM `hilos_status_incident` WHERE `id` = 10');
        $query = new StatusHistoryQuery(anchor: new StatusHistoryAnchor(1000, 0, 10));
        self::assertSame([2], array_column(Hilos::$db->statusIncidents->historyPortion($query)->entries, 'sourceId'));
        self::assertSame([], Hilos::$db->statusMaintenancePeriods->historyPortion($query)->entries);
    }

    /** @return iterable<string, array{string}> Both canonical tuple orders */
    public static function tableOrders(): iterable
    {
        yield 'ascending' => ['asc'];
        yield 'descending' => ['desc'];
    }

    /**
     * @param string $order Canonical order
     */
    #[DataProvider('tableOrders')]
    public function testMergedKeysetsNumericTiesBeforeAndFocusOutsideWindow(string $order): void
    {
        for ($id = 1; $id <= 70; ++$id) {
            $this->incident($id, 1000);
            $this->period($id, 1000);
        }
        $this->incident(71, 1000, null, null);
        $this->period(71, 1000, false);
        $table = new HilosStatusHistoryTable();
        $sort = TableSortOrderDTO::of(new TableSortDTO('startedAt', $order));
        $expected = [...array_map(static fn(int $id): string => 'incident:' . $id, range(1, 70)),
            ...array_map(static fn(int $id): string => 'maintenance:' . $id, range(1, 70))];
        if ($order === 'desc') {
            $expected = array_reverse($expected);
        }
        $seen = [];
        $anchor = null;
        $previous = null;
        for ($page = 0; $page < 5; ++$page) {
            $window = $table->getPage(new TableQueryDTO(sort: $sort, anchor: $anchor));
            $keys = array_map(static fn(HilosStatusHistoryTableRow $row): string => $row->id, $window->rows);
            self::assertSame(array_slice($expected, $page * 32, 32), $keys);
            self::assertSame(140, $window->totalCount);
            self::assertTrue($window->totalExact);
            self::assertSame($page * 32, $window->rowsBefore);
            self::assertSame($table->anchorForRow($window->rows[0], new TableQueryDTO())->values, $window->firstAnchor->values);
            self::assertSame($table->anchorForRow($window->rows[array_key_last($window->rows)], new TableQueryDTO())->values,
                $window->lastAnchor->values);
            if ($previous !== null) {
                $back = $table->getPage(new TableQueryDTO(sort: $sort, anchor: $window->firstAnchor,
                    anchorDirection: TableAnchorDirection::Before));
                self::assertSame($previous, array_column($back->rows, 'id'));
                self::assertSame(($page - 1) * 32, $back->rowsBefore);
            }
            $seen = [...$seen, ...$keys];
            $anchor = $window->lastAnchor;
            $previous = $keys;
        }
        self::assertSame($expected, $seen);
        $pastEnd = $table->getPage(new TableQueryDTO(sort: $sort, anchor: $anchor));
        self::assertSame([], $pastEnd->rows);
        self::assertSame(140, $pastEnd->rowsBefore);
        $focus = $table->findRow($expected[100]);
        self::assertSame($expected[100], $focus->id);
        self::assertTrue($table->containsRow($focus->id, new TableQueryDTO()));
        self::assertSame(0, $table->placeRowAgainst($focus, $table->anchorForRow($focus, new TableQueryDTO()), new TableQueryDTO()));
        self::assertNull($table->findRow('incident:71'));
        self::assertNull($table->findRow('maintenance:71'));
        self::assertNull($table->findRow('incident:999'));
        self::assertNull($table->findRow(2));
        self::assertFalse($table->containsRow('incident:02', new TableQueryDTO()));
    }

    /** A mixed numbered jump needs more than 32 rows from each source; skip is applied only after merging. */
    public function testMergedNumberedWindowsFromEitherEdgeAndGlobalCountCeiling(): void
    {
        $table = new HilosStatusHistoryTable();
        $expected = [];
        for ($id = 100; $id >= 1; --$id) {
            $this->incident($id, $id * 2);
            $this->period($id, $id * 2 + 1);
            array_push($expected, 'maintenance:' . $id, 'incident:' . $id);
        }
        $capped = $table->getPage(new TableQueryDTO(limit: 1000));
        self::assertSame(32, $capped->limit);
        self::assertSame(array_slice($expected, 0, 32), array_column($capped->rows, 'id'));
        foreach (['desc', 'asc'] as $order) {
            $keys = $order === 'desc' ? $expected : array_reverse($expected);
            foreach ([0, 1, 2, 3, 5, 6, 7, PHP_INT_MAX] as $index) {
                $window = $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO('startedAt', $order)),
                    pageIndex: $index));
                self::assertSame($index < 7 ? array_slice($keys, $index * 32, 32) : [], array_column($window->rows, 'id'));
                self::assertSame(200, $window->totalCount);
                self::assertTrue($window->totalExact);
                if ($index < 7) {
                    self::assertSame($index * 32, $window->rowsBefore);
                }
            }
        }
        for ($id = 101; $id <= 260; ++$id) {
            $this->incident($id, $id * 2);
            $this->period($id, $id * 2 + 1);
        }
        self::assertTrue(Hilos::$db->statusIncidents->historyCount(new StatusHistoryQuery())->exact);
        self::assertTrue(Hilos::$db->statusMaintenancePeriods->historyCount(new StatusHistoryQuery())->exact);
        $window = $table->getPage(new TableQueryDTO(pageIndex: 15));
        self::assertSame(500, $window->totalCount);
        self::assertFalse($window->totalExact);
        self::assertNull($window->rowsBefore);
        self::assertSame('maintenance:20', $window->rows[0]->id);
        self::assertSame('incident:5', $window->rows[31]->id);
        self::assertSame([], $table->getPage(new TableQueryDTO(pageIndex: 16))->rows);
        foreach ([Hilos::$db->statusIncidents, Hilos::$db->statusMaintenancePeriods] as $source) {
            self::assertSame([], new ReflectionProperty(Objects::class, 'objects')->getValue($source->getObjectCollection()));
        }
        $half = $table->getPage(new TableQueryDTO(anchor: new TableAnchorDTO(['startedAt' => 301, 'kindRank' => 1, 'sourceId' => 150])));
        self::assertFalse($half->totalExact);
        self::assertNull($half->rowsBefore);
    }

    /**
     * @param string $day Calendar day spanning a clock change
     * @param string $utcStart Independently specified UTC boundary
     * @param string $utcEnd Independently specified UTC boundary after the day
     */
    #[DataProvider('dstDays')]
    public function testCalendarPeriodUsesStartAndActualDstDay(string $day, string $utcStart, string $utcEnd): void
    {
        $from = new DateTimeImmutable($utcStart)->getTimestamp() * 1000;
        $to = new DateTimeImmutable($utcEnd)->getTimestamp() * 1000;
        foreach ([1 => $from - 1, 2 => $from, 3 => $to - 1, 4 => $to] as $id => $at) {
            $this->incident($id, $at);
            $this->period($id, $at);
        }
        $table = new HilosStatusHistoryTable();
        $filters = ['from' => $day, 'to' => $day, 'timeZone' => 'Europe/Warsaw'];
        $query = new TableQueryDTO(filter: $filters);
        self::assertSame(['maintenance:3', 'incident:3', 'maintenance:2', 'incident:2'], array_column($table->getPage($query)->rows, 'id'));
        self::assertTrue($table->containsRow('incident:2', $query));
        self::assertFalse($table->containsRow('maintenance:4', $query));
        self::assertSame(4, $table->facetCounts($query, ['outcome' => ['resolved']])['outcome']['any']->count);
        unset($filters['to']);
        self::assertSame(6, $table->getPage(new TableQueryDTO(filter: $filters))->totalCount);
        unset($filters['from']);
        $filters['to'] = $day;
        self::assertSame(6, $table->getPage(new TableQueryDTO(filter: $filters))->totalCount);
    }

    /** @return iterable<string, array{string, string, string}> Short and long Warsaw calendar days */
    public static function dstDays(): iterable
    {
        yield '23 hours' => ['2026-03-29', '2026-03-28T23:00:00Z', '2026-03-29T22:00:00Z'];
        yield '25 hours' => ['2026-10-25', '2026-10-24T22:00:00Z', '2026-10-25T23:00:00Z'];
    }

    /** Unsupported columns and arbitrary combinations fall back through the ordinary sort whitelist. */
    public function testUnindexedSortsNeverReachTheSources(): void
    {
        $this->incident(2, 1000);
        $this->period(2, 1000);
        $table = new HilosStatusHistoryTable();
        foreach (['title', 'outcome', 'duration', 'id', 'sourceId'] as $field) {
            $window = $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO($field, 'asc'))));
            self::assertSame(['maintenance:2', 'incident:2'], array_column($window->rows, 'id'));
        }
        $window = $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(
            new TableSortDTO('startedAt', 'asc'), new TableSortDTO('sourceId', 'desc'))));
        self::assertSame(['maintenance:2', 'incident:2'], array_column($window->rows, 'id'));
    }
}
