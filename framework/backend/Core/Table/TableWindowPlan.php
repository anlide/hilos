<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Database\Filter\KeysetAnchorFilter;
use Hilos\Database\SqlSortDirection;

/**
 * How one SQL path runs the window a query asked for.
 *
 * Every row source that reaches its rows with SQL answers the same descriptor, and the reading
 * of it is the same work each time: which way the ORDER BY runs, whether the rows come back
 * turned over, what condition places the window, and - for a jump to a numbered page, the one
 * address an anchor cannot express - how many rows to skip and from which end.
 *
 * Paging back is run as the order turned over, because LIMIT takes rows from the start of what
 * it is given: asked for the rows before an anchor in the set's own order, it would hand back
 * the start of the set instead. The rows are turned over again before they leave, so the window
 * always arrives in the set's own order.
 *
 * A numbered page is counted from whichever end of the set is nearer, which is the whole reason
 * the far half is read turned over. The worst skip is half the set rather than all of it, and
 * the last page costs what the first one does.
 */
final readonly class TableWindowPlan
{
    /**
     * @param array<string, string> $orderBy Order the query runs in, turned over when the window runs back
     * @param int $offset Rows to skip, zero unless a numbered page was jumped to
     * @param int $limit Rows to take (TableConstants::NO_LIMIT = all of them)
     * @param bool $reversed Whether the rows arrive in the opposite order and must be turned over
     * @param ?KeysetAnchorFilter $keyset Condition placing the window, or null when it starts at an edge
     */
    private function __construct(
        public array $orderBy,
        public int $offset,
        public int $limit,
        public bool $reversed,
        public ?KeysetAnchorFilter $keyset,
    ) {
    }

    /**
     * Reads the window descriptor of a query into the query the row source has to run.
     *
     * @param TableQueryDTO $query Window query
     * @param array<string, string> $orderBy Key column => SqlSortDirection the whole set is ordered by
     * @param int $totalCount Rows matching the filter, which is what a numbered page is placed against
     * @return ?self How to run the query, or null when the page asked for lies past the end of the set
     */
    public static function forQuery(TableQueryDTO $query, array $orderBy, int $totalCount): ?self
    {
        if ($query->limit === TableConstants::NO_LIMIT) {
            return new self($orderBy, 0, TableConstants::NO_LIMIT, false, null);
        }

        if ($query->pageIndex !== null) {
            $start = max(0, $query->pageIndex) * $query->limit;
            $end = min($totalCount, $start + $query->limit);
            if ($start >= $end) {
                return null;
            }

            return $start * 2 >= $totalCount
                ? new self(self::inverted($orderBy), $totalCount - $end, $end - $start, true, null)
                : new self($orderBy, $start, $end - $start, false, null);
        }

        $reversed = $query->anchorDirection === TableAnchorDirection::Before;

        return new self(
            $reversed ? self::inverted($orderBy) : $orderBy,
            0,
            $query->limit,
            $reversed,
            $query->anchor === null ? null : new KeysetAnchorFilter($orderBy, $query->anchor, $query->anchorDirection),
        );
    }

    /**
     * Turns an order over, which is how the rows on the far side of an anchor or of the set are reached.
     *
     * @param array<string, string> $orderBy Column => SqlSortDirection map the set is ordered by
     * @return array<string, string> The same columns, each running the other way
     */
    private static function inverted(array $orderBy): array
    {
        $inverted = [];
        foreach ($orderBy as $column => $direction) {
            $inverted[$column] = $direction === SqlSortDirection::ASC ? SqlSortDirection::DESC : SqlSortDirection::ASC;
        }

        return $inverted;
    }
}
