<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Closure;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowFrameDTO;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * In-memory search, sort and pagination for tables that already have all rows in PHP memory.
 *
 * Tables that can push these operations to SQL or another backend should
 * implement query handling directly instead of using this helper.
 */
final class InMemoryTableFilter
{
    /**
     * Applies search, sort and the window to an in-memory row set.
     *
     * The sort is settled by the row key: a sorted field with repeats orders the rows that
     * share a value arbitrarily, and two neighbouring pages sliced out of two such orderings
     * can show one row twice and another not at all. The key field is asked of the caller
     * rather than guessed, and it is required rather than optional so that a new table cannot
     * lose its tie-breaker without saying so — {@see TableDefinition::filterInMemory()} is
     * where a table gets it from its own row class.
     *
     * The window is taken from the anchor by walking to the place it names, which is what keeps
     * a row deleted above the window from shifting it. Where a column was sorted by, the walk
     * compares values and needs no row to still exist. Where none was, the order is the row
     * source's own — newest batch first, the roster's own sequence — and no comparison of field
     * values reproduces it, so the anchor is found by its key instead; a row deleted out from
     * under it sends the window back to the edge it was heading away from.
     *
     * A jump to a numbered page still counts rows, because a page nobody has shown has no anchor
     * to walk to; here that costs nothing, the whole set being in memory already.
     *
     * The count is exact and carries no ceiling, for the same reason: the rows are already in
     * hand, so counting them costs nothing to save. Reporting the ceiling here would be lying
     * about a number the filter is holding.
     *
     * The search reads the fields the table declared it over and no others, which is what makes
     * the answer here the same as the one a database-backed table gives: the caller carries the
     * declaration in the query, and the keys of it are the payload fields a row is keyed by.
     * {@see TableDefinition::filterInMemory()} is what holds a declaration against the set before
     * the search runs, so an unreachable field is refused rather than quietly searched past.
     *
     * The places framing the window are read off the same ordered set and travel in the snapshot
     * beside it, in the fields its anchors use; they are never rows of the window.
     *
     * @param list<array<string, mixed>> $rows All rows to filter
     * @param TableQueryDTO $query Query parameters
     * @param string $keyField Payload field the row key travels under, used to settle the sort
     * @return TableSnapshotDTO Filtered, sorted and windowed snapshot
     */
    public static function apply(array $rows, TableQueryDTO $query, string $keyField): TableSnapshotDTO
    {
        $rows = self::searched($rows, $query);

        $order = $query->sort;
        if ($order !== null) {
            usort($rows, static fn(array $a, array $b): int => self::compare($a, $b, $order, $keyField));
        }

        $totalCount = count($rows);
        $anchorFields = self::anchorFields($order, $keyField);
        [$window, $rowsBefore, $frame] = self::window($rows, $query, $keyField, $anchorFields);

        return new TableSnapshotDTO(
            rows: $window,
            totalCount: $totalCount,
            totalExact: true,
            limit: $query->limit,
            firstAnchor: $window === [] ? null : TableAnchorDTO::fromRow($window[0], $anchorFields),
            lastAnchor: $window === [] ? null : TableAnchorDTO::fromRow($window[count($window) - 1], $anchorFields),
            rowsBefore: $rowsBefore,
            frame: $frame,
        );
    }

    /**
     * Keeps the rows that answer the query's search, in the order they came in.
     *
     * This is the search of {@see self::apply()} and nothing past it - no sort, no window - so a
     * question about the set alone, such as whether one row is in it, costs what the set costs and
     * not what a page of it does. A query with no search term, or one over no declared field,
     * keeps every row.
     *
     * @param list<array<string, mixed>> $rows Rows to search
     * @param TableQueryDTO $query Query whose search term and declared fields describe the search
     * @return list<array<string, mixed>> Rows that answer the search
     */
    public static function searched(array $rows, TableQueryDTO $query): array
    {
        $search = TableSearchTerm::normalize($query->search);
        if ($search === null || $query->searchableFields === []) {
            return $rows;
        }

        $matchers = [];
        foreach ($query->searchableFields as $field => $declared) {
            $matchers[$field] = TableSearchTerm::matcher($search, TableSearchField::of($declared)->match);
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => self::matchesSearch($row, $matchers),
        ));
    }

    /**
     * Whether one row answers the search term in a field the table declared the search over.
     *
     * Each field is compared the way the table declared it, so a star is a mask in one field and a
     * character in the next, and a field the row has no value for matches nothing rather than
     * matching an empty string.
     *
     * @param array<string, mixed> $row Row of the set being searched
     * @param array<string, Closure(string): bool> $matchers Comparison of the term, keyed by the payload field it reads
     * @return bool Whether the row belongs to the searched set
     */
    private static function matchesSearch(array $row, array $matchers): bool
    {
        foreach ($matchers as $field => $matches) {
            $value = $row[$field] ?? null;
            if ($value !== null && $matches((string) $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cuts the window the query asked for out of the ordered rows, and says where it was cut from.
     *
     * The place is the index the cut started at, which is the same number whether the slice came
     * back with rows or empty: a numbered page past the end of the set still starts where the
     * pages before it end, and an anchor pointing past the last row starts at the end of the set.
     * That is what the window reports as the rows standing before it, and nothing here costs an
     * extra pass — the set is already in hand, ordered and counted.
     *
     * The frame is read by the same rule the SQL paths follow ({@see TableWindowPlan}): the side of
     * an anchored window that faces its anchor is framed by the anchor itself, every other side by
     * the row standing next to the window, and a side with no such row is the edge of the set. An
     * empty window reports no frame, so a page past the end does not call itself the edge.
     *
     * @param list<array<string, mixed>> $rows Ordered rows of the whole filtered set
     * @param TableQueryDTO $query Query parameters
     * @param string $keyField Payload field the row key travels under
     * @param list<string> $anchorFields Fields a place in this order is named by
     * @return array{list<array<string, mixed>>, int, ?TableWindowFrameDTO} Rows of the window in the set's
     *     own order, how many rows of the set stand before them, and the places framing them
     */
    private static function window(array $rows, TableQueryDTO $query, string $keyField, array $anchorFields): array
    {
        if ($query->limit === TableConstants::NO_LIMIT) {
            return [$rows, 0, $rows === [] ? null : new TableWindowFrameDTO()];
        }

        $takesFromEnd = $query->anchorDirection === TableAnchorDirection::Before;
        if ($query->pageIndex !== null) {
            $start = max(0, $query->pageIndex) * $query->limit;
            $window = array_slice($rows, $start, $query->limit);
        } elseif ($takesFromEnd) {
            $end = $query->anchor === null
                ? count($rows)
                : self::boundary($rows, $query->anchor, $query->sort, $keyField, true);
            $start = max(0, $end - $query->limit);
            $window = array_slice($rows, $start, $end - $start);
        } else {
            $start = $query->anchor === null ? 0 : self::boundary($rows, $query->anchor, $query->sort, $keyField, false);
            $window = array_slice($rows, $start, $query->limit);
        }

        if ($window === []) {
            return [$window, $start, null];
        }

        $before = self::placeAt($rows, $start - 1, $anchorFields);
        $after = self::placeAt($rows, $start + count($window), $anchorFields);
        if ($query->pageIndex !== null) {
            return [$window, $start, new TableWindowFrameDTO($before, $after)];
        }

        return [
            $window,
            $start,
            $takesFromEnd ? new TableWindowFrameDTO($before, $query->anchor) : new TableWindowFrameDTO($query->anchor, $after),
        ];
    }

    /**
     * Reads the place the row at one index of the ordered set sits at.
     *
     * @param list<array<string, mixed>> $rows Ordered rows of the whole filtered set
     * @param int $index Index of the row, which may lie outside the set
     * @param list<string> $anchorFields Fields a place in this order is named by
     * @return ?TableAnchorDTO Place of that row, or null when the set holds no row there
     */
    private static function placeAt(array $rows, int $index, array $anchorFields): ?TableAnchorDTO
    {
        return isset($rows[$index]) ? TableAnchorDTO::fromRow($rows[$index], $anchorFields) : null;
    }

    /**
     * Walks the rows to the place the anchor names.
     *
     * @param list<array<string, mixed>> $rows Rows of the whole filtered set, in the set's own order
     * @param TableAnchorDTO $anchor Place the window is taken from
     * @param ?TableSortOrderDTO $order Order the window asked for, or null when the source ordered the rows
     * @param string $keyField Payload field the row key travels under
     * @param bool $takesFromEnd Whether the window runs back from the anchor rather than on from it
     * @return int Index the window starts at, or ends before when it runs back
     */
    private static function boundary(
        array $rows,
        TableAnchorDTO $anchor,
        ?TableSortOrderDTO $order,
        string $keyField,
        bool $takesFromEnd,
    ): int {
        if ($order === null) {
            return self::keyBoundary($rows, $anchor, $keyField, $takesFromEnd);
        }

        foreach ($rows as $index => $row) {
            $comparison = self::compare($row, $anchor->values, $order, $keyField);
            if ($takesFromEnd ? $comparison >= 0 : $comparison > 0) {
                return $index;
            }
        }

        return count($rows);
    }

    /**
     * Finds the anchored row in a set whose order is the row source's own.
     *
     * Nothing here compares two rows: the order was not built out of their values and cannot be
     * reproduced from them, so the anchor is the one row carrying its key. A row that is no longer
     * there leaves the window with no place to continue from, and it goes back to the edge it was
     * heading away from — the same window a null anchor asks for.
     *
     * @param list<array<string, mixed>> $rows Rows of the whole filtered set, in the source's order
     * @param TableAnchorDTO $anchor Place the window is taken from
     * @param string $keyField Payload field the row key travels under
     * @param bool $takesFromEnd Whether the window runs back from the anchor rather than on from it
     * @return int Index the window starts at, or ends before when it runs back
     */
    private static function keyBoundary(array $rows, TableAnchorDTO $anchor, string $keyField, bool $takesFromEnd): int
    {
        $anchorKey = $anchor->values[$keyField] ?? null;
        if ($anchorKey !== null) {
            foreach ($rows as $index => $row) {
                if (($row[$keyField] ?? null) == $anchorKey) {
                    return $takesFromEnd ? $index : $index + 1;
                }
            }
        }

        return $takesFromEnd ? count($rows) : 0;
    }

    /**
     * Places one set of row values against another, the walk down the order the ordering makes.
     *
     * Two rows and a row against an anchor are the same comparison, which is why they run
     * through one place: an anchor is the values a row carried in the fields the order is
     * settled by, so putting a row beside it asks exactly what putting it beside that row did.
     *
     * It is public for that third caller — a row that has just been created, placed against the
     * boundary of a window that is already standing ({@see TableDefinition::placeRowAgainst()}).
     * A second comparator beside this one would be a second description of one order, and the
     * two would disagree about the row a window is built to hold.
     *
     * @param array<string, mixed> $values Values of the row to place
     * @param array<string, mixed> $against Values to place it against — another row, or an anchor's
     * @param TableSortOrderDTO $order Order the window asked for
     * @param string $keyField Payload field the row key travels under
     * @return int Negative, zero or positive in the ordering's sense
     */
    public static function compare(array $values, array $against, TableSortOrderDTO $order, string $keyField): int
    {
        foreach ($order->components as $component) {
            $direction = self::directionSign($component);
            $byValue = self::compareValues($values[$component->field] ?? null, $against[$component->field] ?? null, $direction);
            if ($byValue !== 0) {
                return $byValue;
            }
        }

        return self::directionSign($order->last())
            * self::compareKeys($values[$keyField] ?? null, $against[$keyField] ?? null);
    }

    /**
     * Reads which way one component of an order runs.
     *
     * @param TableSortDTO $component Component of the order
     * @return int 1 ascending, -1 descending
     */
    private static function directionSign(TableSortDTO $component): int
    {
        return strtolower($component->direction) === TableConstants::ORDER_DESC ? -1 : 1;
    }

    /**
     * Names the fields an anchor of this window has to carry.
     *
     * They are the fields the order is settled by, in the sequence it settles them, with the row
     * key last: an anchor names a place in the order, and a place is only named once every field
     * that decides it has a value.
     *
     * It is public alongside {@see compare()} and for the same reason: this list is what says
     * whether an anchor belongs to the order at all, and a caller that wrote its own copy of it
     * would eventually be comparing against a place named by different fields than the ones the
     * window was cut by.
     *
     * @param ?TableSortOrderDTO $order Order the window asked for, or null when the source ordered the rows
     * @param string $keyField Payload field the row key travels under
     * @return list<string> Fields the anchor carries, in the order's own sequence
     */
    public static function anchorFields(?TableSortOrderDTO $order, string $keyField): array
    {
        $fields = [];
        foreach ($order?->components ?? [] as $component) {
            if ($component->field !== $keyField && !in_array($component->field, $fields, true)) {
                $fields[] = $component->field;
            }
        }
        $fields[] = $keyField;

        return $fields;
    }

    /**
     * Compares two values of the sorted column, nulls first ascending.
     *
     * @param mixed $a Value of the sorted field in the left row, or null when the row has none
     * @param mixed $b Value of the sorted field in the right row, or null when the row has none
     * @param int $direction 1 ascending, -1 descending
     * @return int Negative, zero or positive in the usort sense
     */
    private static function compareValues(mixed $a, mixed $b, int $direction): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return $direction;
        }
        if ($b === null) {
            return -$direction;
        }

        return $direction * strnatcasecmp((string) $a, (string) $b);
    }

    /**
     * Compares two row keys, which is what settles a tie between equal column values.
     *
     * Two integers are compared as numbers and anything else as text, because `10` sorts
     * after `9` while `'10'` sorts before `'9'` and only the row itself knows which it is.
     * Text comparison is case-sensitive on purpose: the case-insensitive comparison the
     * sorted column uses would call the keys `a` and `A` one key and settle nothing.
     * A key the payload does not carry cannot separate anything, so such rows compare
     * equal and keep the relative order the stable sort gives them.
     *
     * @param mixed $a Row key of the left row, or null when the payload carries none
     * @param mixed $b Row key of the right row, or null when the payload carries none
     * @return int Negative, zero or positive in the usort sense
     */
    private static function compareKeys(mixed $a, mixed $b): int
    {
        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }
        if ((!is_int($a) && !is_string($a)) || (!is_int($b) && !is_string($b))) {
            return 0;
        }

        return strcmp((string) $a, (string) $b);
    }
}
