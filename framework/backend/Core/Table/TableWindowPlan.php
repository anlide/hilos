<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Closure;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableWindowFrameDTO;
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
 *
 * Both of those readings need the size of the set, so neither survives a count that stopped at
 * its ceiling: which end is nearer and where the set ends are answers derived from the exact
 * number. A numbered page against such a count is therefore run the plain way — skip from the
 * start, take the window — and no page is refused as lying past an end nobody has found.
 *
 * The query takes more than the window: it also takes the places framing it, so the subscription
 * can tell a row that moved within the page from a row that left it without asking the source
 * again. The side a window is taken from an anchor is framed by the anchor itself, because the
 * window is the rows strictly after (or before) it ({@see KeysetAnchorFilter::strictSql()}), and
 * no place sits closer; only the far side costs one row more. A numbered page has no anchor, so
 * it takes one row more on both sides - one row further back on the skip, never past the start
 * of the set on page zero, nor past its end when the page is counted from the end. The one
 * piece of work that tells frame rows from window rows is {@see self::cut()}, shared by every
 * SQL path, because turning the rows over and taking the frame off them is easy to write two
 * ways and impossible to tell apart by eye.
 *
 * A window that came back empty reports no frame: there is nothing in it to judge, and a page
 * past the end of the set would otherwise report itself as the edge. Rows missing from the far
 * end of what the query returned mean the set ended there - which is what it did at the moment
 * of the read, whatever a count taken before it said.
 */
final readonly class TableWindowPlan
{
    /**
     * @param array<string, string> $orderBy Order the query runs in, turned over when the window runs back
     * @param int $offset Rows to skip, zero unless a numbered page was jumped to, the frame row included
     * @param int $limit Rows to take, the frame rows included (TableConstants::NO_LIMIT = all of them)
     * @param bool $reversed Whether the rows arrive in the opposite order and must be turned over
     * @param ?KeysetAnchorFilter $keyset Condition placing the window, or null when it starts at an edge
     * @param int $window Rows of the window itself (TableConstants::NO_LIMIT = all of them)
     * @param bool $leadingFrame Whether the first row the query returns frames the near side rather than
     *     belongs to the window
     * @param ?TableAnchorDTO $nearFrame Place framing the near side when no row is taken for it: the anchor
     *     of the query, or null for the edge of the set
     */
    private function __construct(
        public array $orderBy,
        public int $offset,
        public int $limit,
        public bool $reversed,
        public ?KeysetAnchorFilter $keyset,
        public int $window,
        public bool $leadingFrame,
        public ?TableAnchorDTO $nearFrame,
    ) {
    }

    /**
     * Reads the window descriptor of a query into the query the row source has to run.
     *
     * @param TableQueryDTO $query Window query
     * @param array<string, string> $orderBy Key column => SqlSortDirection the whole set is ordered by
     * @param int $totalCount Rows matching the filter, which is what a numbered page is placed against
     * @param bool $totalExact Whether that count is the size of the set rather than the ceiling it stopped at
     * @return ?self How to run the query, or null when the page asked for lies past the end of the set
     */
    public static function forQuery(TableQueryDTO $query, array $orderBy, int $totalCount, bool $totalExact): ?self
    {
        if ($query->limit === TableConstants::NO_LIMIT) {
            return new self($orderBy, 0, TableConstants::NO_LIMIT, false, null, TableConstants::NO_LIMIT, false, null);
        }

        if ($query->pageIndex !== null) {
            $start = max(0, $query->pageIndex) * $query->limit;
            if (!$totalExact) {
                return self::numbered($orderBy, $start, $query->limit, false, $start > 0);
            }

            $end = min($totalCount, $start + $query->limit);
            if ($start >= $end) {
                return null;
            }

            return $start * 2 >= $totalCount
                ? self::numbered(self::inverted($orderBy), $totalCount - $end, $end - $start, true, $end < $totalCount)
                : self::numbered($orderBy, $start, $end - $start, false, $start > 0);
        }

        $reversed = $query->anchorDirection === TableAnchorDirection::Before;

        return new self(
            $reversed ? self::inverted($orderBy) : $orderBy,
            0,
            $query->limit + 1,
            $reversed,
            $query->anchor === null ? null : new KeysetAnchorFilter($orderBy, $query->anchor, $query->anchorDirection),
            $query->limit,
            false,
            $query->anchor,
        );
    }

    /**
     * Cuts what the query returned into the window and the places framing it.
     *
     * The frame rows are taken off before anything is built from the rows, so the window, its
     * boundary anchors and its place in the set stay exactly what they were without the frame.
     *
     * @template T
     * @param array<int|string, T> $fetched Rows the query returned, in the order it returned them, keys kept
     * @param Closure(T): TableAnchorDTO $placeOf Place one fetched row sits at, in the keys the window's anchors use
     * @return array{0: array<int|string, T>, 1: ?TableWindowFrameDTO} Window rows in the set's own order with
     *     their keys, and the places framing them; null when the window came back empty
     */
    public function cut(array $fetched, Closure $placeOf): array
    {
        if ($this->window === TableConstants::NO_LIMIT) {
            return [$fetched, $fetched === [] ? null : new TableWindowFrameDTO()];
        }

        $near = $this->nearFrame;
        if ($this->leadingFrame) {
            $leadingKey = array_key_first($fetched);
            if ($leadingKey === null) {
                return [[], null];
            }

            $near = $placeOf($fetched[$leadingKey]);
            unset($fetched[$leadingKey]);
        }

        $rows = array_slice($fetched, 0, $this->window, preserve_keys: true);
        if ($rows === []) {
            return [[], null];
        }

        $farRows = array_slice($fetched, $this->window, 1, preserve_keys: true);
        $far = $farRows === [] ? null : $placeOf($farRows[array_key_first($farRows)]);
        if ($this->reversed) {
            return [array_reverse($rows, preserve_keys: true), new TableWindowFrameDTO($far, $near)];
        }

        return [$rows, new TableWindowFrameDTO($near, $far)];
    }

    /**
     * Plans a numbered page: the skip and the take widened by the rows framing it.
     *
     * @param array<string, string> $orderBy Order the query runs in, already turned over when the page is counted
     *     from the end
     * @param int $skip Rows standing between the end the page is counted from and the page itself
     * @param int $window Rows of the page itself
     * @param bool $reversed Whether the page is counted from the end of the set
     * @param bool $leadingFrame Whether a row stands between that end and the page, so one more is taken for it
     * @return self How to run the page with its frame
     */
    private static function numbered(array $orderBy, int $skip, int $window, bool $reversed, bool $leadingFrame): self
    {
        $lead = $leadingFrame ? 1 : 0;

        return new self($orderBy, $skip - $lead, $window + 1 + $lead, $reversed, null, $window, $leadingFrame, null);
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
