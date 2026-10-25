<?php

declare(strict_types=1);

namespace Hilos\Tables\Analytics;

use Hilos\Core\Analytics\AnalyticsPeriodFilter;
use Hilos\Core\Analytics\AnalyticsSectionPerson;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Analytics\AnalyticsWindowAnchor;
use Hilos\Core\Analytics\AnalyticsWindowOrder;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Core\Table\TableWindowPlan;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/** Frozen windows of people grouped from their matching browser sessions. */
class HilosAnalyticsPeopleTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosAnalyticsPeople';

    private const string ROW_SLOT = 'person';
    private const int WINDOW_SIZE = 25;
    private const int MAX_SAFE_USER_ID = 9007199254740991;
    private const int MAX_SAFE_TS_MS = 9007199254740991;
    private const array PERIODS = [AnalyticsPeriodFilter::THIS_HOUR, AnalyticsPeriodFilter::TODAY, AnalyticsPeriodFilter::THIS_WEEK];

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /** @return TableSortOrderDTO Default source-time order */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(
            HilosAnalyticsPersonTableRow::lastSeenTs,
            TableConstants::ORDER_DESC,
        ));
    }

    /**
     * @param SourceChange $change Source event deliberately ignored by this frozen table
     * @return ?TableRowMutationDTO No live row source belongs to this table
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Analytics row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser row envelope
     * @throws TableRowKeyMissingException When the row has no stable key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        $values = $row->toArray();
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => [
                HilosAnalyticsPersonTableRow::name => $values[HilosAnalyticsPersonTableRow::name],
                HilosAnalyticsPersonTableRow::sessionCount => $values[HilosAnalyticsPersonTableRow::sessionCount],
                HilosAnalyticsPersonTableRow::lastSeenTs => $values[HilosAnalyticsPersonTableRow::lastSeenTs],
            ]],
        ];
    }

    /**
     * @param TableQueryDTO $query Captured calendar context and current period
     * @param array<string, list<int|float|string|bool>> $wanted Requested period choices
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Allowed period tallies
     * @throws HilosException When the context is invalid or a grouped count fails
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        $asOfTs = TimeHelper::nowMs();
        $filter = AnalyticsPeriodFilter::fromArray($query->filter, $asOfTs);
        $periods = isset($wanted[AnalyticsPeriodFilter::period])
            ? [AnalyticsPeriodFilter::period => array_values(array_unique(array_filter(
                $wanted[AnalyticsPeriodFilter::period],
                static fn(mixed $value): bool => in_array($value, self::PERIODS, true),
            )))] : [];
        $reader = new AnalyticsSectionReader();
        return TableFacetTally::forFilters(
            $query,
            $periods,
            static function (TableQueryDTO $set) use ($reader, $filter, $asOfTs): TableFacetCountDTO {
                $count = $filter->hasContext()
                    ? $reader->personCount(AnalyticsPeriodFilter::fromArray($set->filter, $asOfTs), $asOfTs) : 0;
                return new TableFacetCountDTO(min($count, TableConstants::COUNT_CEILING), $count <= TableConstants::COUNT_CEILING);
            },
        );
    }

    /** @return array<string, string> Supported source-time field and its analytics column */
    protected function sortableFields(): array
    {
        return [HilosAnalyticsPersonTableRow::lastSeenTs => 'last_seen_ts'];
    }

    /**
     * @param TableQueryDTO $query Captured calendar context and viewport request
     * @return TableSnapshotDTO Frozen analytics window
     * @throws ValidationException When the calendar context or anchor is invalid
     * @throws HilosException When the analytics reader cannot build the window
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $limit = $query->limit === TableConstants::NO_LIMIT
            ? AnalyticsSectionReader::MAX_PAGE_ROWS : min($query->limit, AnalyticsSectionReader::MAX_PAGE_ROWS);
        $served = $query->withLimit($limit);
        $asOfTs = TimeHelper::nowMs();
        $filter = AnalyticsPeriodFilter::fromArray($query->filter, $asOfTs);
        $order = $query->sort === null ? AnalyticsWindowOrder::NUMBER
            : ($query->sort->last()->direction === TableConstants::ORDER_ASC
                ? AnalyticsWindowOrder::TIME_ASC : AnalyticsWindowOrder::TIME_DESC);
        $anchor = $query->anchor === null ? null : self::readAnchor($query->anchor, $order);
        if (!$filter->hasContext()) {
            return new TableSnapshotDTO(limit: $limit, rowsBefore: 0);
        }
        $reader = new AnalyticsSectionReader();
        $count = $reader->personCount($filter, $asOfTs);
        $exact = $count <= TableConstants::COUNT_CEILING;
        $total = min($count, TableConstants::COUNT_CEILING);

        $direction = $query->anchorDirection;
        $skip = 0;
        $take = $limit;
        if ($query->pageIndex !== null) {
            $plan = TableWindowPlan::numberedSlice($query->pageIndex, $limit, $total, $exact);
            if ($plan === null) {
                return new TableSnapshotDTO(
                    totalCount: $total, totalExact: $exact, limit: $limit,
                    rowsBefore: $exact ? TableWindowPlan::knownRowsBefore($served, null, $total) : null,
                );
            }
            $direction = $plan['direction'];
            $skip = $plan['skip'];
            $take = $plan['take'];
        }
        $items = $reader->personWindow($filter, $order, $anchor, $direction, $take, $skip, $asOfTs);
        $first = $items === [] ? null : self::anchorFor($items[0], $order);
        $last = $items === [] ? null : self::anchorFor($items[count($items) - 1], $order);
        $rowsBefore = null;
        if ($exact) {
            $rowsBefore = TableWindowPlan::knownRowsBefore($served, $first, $total);
            if ($rowsBefore === null && $first !== null) {
                $rowsBefore = $reader->personCountBefore($filter, $order, self::readAnchor($first, $order), $asOfTs);
            }
        }
        return new TableSnapshotDTO(
            rows: array_map(static fn(AnalyticsSectionPerson $person): HilosAnalyticsPersonTableRow => HilosAnalyticsPersonTableRow::fromPerson(
                $person,
                isset(Hilos::$db->users[$person->userId]) ? Hilos::$db->users[$person->userId]->name : null,
            ), $items),
            totalCount: $total,
            totalExact: $exact,
            limit: $limit,
            firstAnchor: $first,
            lastAnchor: $last,
            rowsBefore: $rowsBefore,
        );
    }

    /** Configures the typed browser row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosAnalyticsPersonTableRow::class);
    }

    /**
     * @param TableAnchorDTO $anchor Browser-returned boundary
     * @param AnalyticsWindowOrder $order Order actually served, narrowed for a viewer
     * @return AnalyticsWindowAnchor Reader key without a hidden moment in row-number order
     * @throws ValidationException When a required component is missing or outside the safe integer range
     */
    private static function readAnchor(TableAnchorDTO $anchor, AnalyticsWindowOrder $order): AnalyticsWindowAnchor
    {
        $id = $anchor->values[HilosAnalyticsPersonTableRow::rowKey] ?? null;
        if (!is_int($id) || $id <= 0 || $id > self::MAX_SAFE_USER_ID) {
            throw new ValidationException('Choose an analytics anchor with a positive safe integer rowKey.');
        }
        $moment = null;
        if ($order !== AnalyticsWindowOrder::NUMBER) {
            $moment = $anchor->values[HilosAnalyticsPersonTableRow::lastSeenTs] ?? null;
            if (!is_int($moment) || $moment < 0 || $moment > self::MAX_SAFE_TS_MS) {
                throw new ValidationException('Choose an analytics anchor with a nonnegative safe integer lastSeenTs.');
            }
        }
        return new AnalyticsWindowAnchor($moment, $id);
    }

    /**
     * @param AnalyticsSectionPerson $item Reader result at the window boundary
     * @param AnalyticsWindowOrder $order Order actually served
     * @return TableAnchorDTO Row address containing only fields of that order
     */
    private static function anchorFor(AnalyticsSectionPerson $item, AnalyticsWindowOrder $order): TableAnchorDTO
    {
        return new TableAnchorDTO($order === AnalyticsWindowOrder::NUMBER
            ? [HilosAnalyticsPersonTableRow::rowKey => $item->userId]
            : [HilosAnalyticsPersonTableRow::lastSeenTs => $item->lastSeenTs, HilosAnalyticsPersonTableRow::rowKey => $item->userId]);
    }
}
