<?php

declare(strict_types=1);

namespace Hilos\Tables\Analytics;

use Hilos\Core\Analytics\AnalyticsSectionAction;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Analytics\AnalyticsWindowAnchor;
use Hilos\Core\Analytics\AnalyticsWindowOrder;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableWindowPlan;
use Hilos\HilosException;

/** Frozen analytics action windows for one numbered subject. */
class HilosAnalyticsActionsTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosAnalyticsActions';
    public const string FILTER_BROWSER_SESSION = 'browserSession';

    private const string ROW_SLOT = 'action';
    private const int WINDOW_SIZE = 25;

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /** @return TableSortOrderDTO Default source-time order */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(
            HilosAnalyticsActionTableRow::createdTs,
            TableConstants::ORDER_ASC,
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
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, string> Supported source-time field and its analytics column */
    protected function sortableFields(): array
    {
        return [HilosAnalyticsActionTableRow::createdTs => 'created_ts'];
    }

    /**
     * @param TableQueryDTO $query Scoped viewport request
     * @return TableSnapshotDTO Frozen analytics window
     * @throws InvalidArgumentException When the subject filter or anchor is invalid
     * @throws HilosException When the analytics reader cannot build the window
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $limit = $query->limit === TableConstants::NO_LIMIT
            ? AnalyticsSectionReader::MAX_PAGE_ROWS : min($query->limit, AnalyticsSectionReader::MAX_PAGE_ROWS);
        $served = $query->withLimit($limit);
        $subject = self::readFilter($query);
        if ($subject === null) {
            return new TableSnapshotDTO(limit: $limit, rowsBefore: 0);
        }
        $order = $query->sort === null ? AnalyticsWindowOrder::NUMBER
            : ($query->sort->last()->direction === TableConstants::ORDER_ASC
                ? AnalyticsWindowOrder::TIME_ASC : AnalyticsWindowOrder::TIME_DESC);
        $anchor = $query->anchor === null ? null : self::readAnchor($query->anchor, $order);
        $reader = new AnalyticsSectionReader();
        $count = $reader->actionCount($subject);
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
        $items = $reader->actionWindow($subject, $order, $anchor, $direction, $take, $skip);
        $first = $items === [] ? null : self::anchorFor($items[0], $order);
        $last = $items === [] ? null : self::anchorFor($items[count($items) - 1], $order);
        $rowsBefore = null;
        if ($exact) {
            $rowsBefore = TableWindowPlan::knownRowsBefore($served, $first, $total);
            if ($rowsBefore === null && $first !== null) {
                $rowsBefore = $reader->actionCountBefore($subject, $order, self::readAnchor($first, $order));
            }
        }
        return new TableSnapshotDTO(
            rows: array_map(HilosAnalyticsActionTableRow::fromAction(...), $items),
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
        $this->setRowClass(HilosAnalyticsActionTableRow::class);
    }

    /**
     * @param TableQueryDTO $query Viewport filter map
     * @return ?int Positive subject number, or null before the page preset arrives
     * @throws InvalidArgumentException When the filter carries anything but a positive integer
     */
    private static function readFilter(TableQueryDTO $query): ?int
    {
        if (!array_key_exists(self::FILTER_BROWSER_SESSION, $query->filter)) {
            return null;
        }
        $subject = $query->filter[self::FILTER_BROWSER_SESSION];
        if (!is_int($subject) || $subject <= 0) {
            throw new InvalidArgumentException('Analytics browserSession filter must be a positive integer');
        }
        return $subject;
    }

    /**
     * @param TableAnchorDTO $anchor Browser-returned boundary
     * @param AnalyticsWindowOrder $order Order actually served, narrowed for a viewer
     * @return AnalyticsWindowAnchor Reader key without a hidden moment in row-number order
     * @throws InvalidArgumentException When a required key component is missing or not an integer
     */
    private static function readAnchor(TableAnchorDTO $anchor, AnalyticsWindowOrder $order): AnalyticsWindowAnchor
    {
        $id = $anchor->values[HilosAnalyticsActionTableRow::rowKey] ?? null;
        if (!is_int($id)) {
            throw new InvalidArgumentException('Analytics anchor requires an integer rowKey');
        }
        $moment = null;
        if ($order !== AnalyticsWindowOrder::NUMBER) {
            $moment = $anchor->values[HilosAnalyticsActionTableRow::createdTs] ?? null;
            if (!is_int($moment)) {
                throw new InvalidArgumentException('Analytics anchor requires an integer createdTs');
            }
        }
        return new AnalyticsWindowAnchor($moment, $id);
    }

    /**
     * @param AnalyticsSectionAction $item Reader result at the window boundary
     * @param AnalyticsWindowOrder $order Order actually served
     * @return TableAnchorDTO Row address containing only fields of that order
     */
    private static function anchorFor(AnalyticsSectionAction $item, AnalyticsWindowOrder $order): TableAnchorDTO
    {
        return new TableAnchorDTO($order === AnalyticsWindowOrder::NUMBER
            ? [HilosAnalyticsActionTableRow::rowKey => $item->id]
            : [HilosAnalyticsActionTableRow::createdTs => $item->createdTs, HilosAnalyticsActionTableRow::rowKey => $item->id]);
    }
}
