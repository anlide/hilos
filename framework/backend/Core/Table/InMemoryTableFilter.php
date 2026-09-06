<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
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
     * @param list<array<string, mixed>> $rows All rows to filter
     * @param TableQueryDTO $query Query parameters
     * @param string $keyField Payload field the row key travels under, used to settle the sort
     * @return TableSnapshotDTO Filtered, sorted and windowed snapshot
     */
    public static function apply(array $rows, TableQueryDTO $query, string $keyField): TableSnapshotDTO
    {
        if ($query->search !== null && $query->search !== '') {
            $search = mb_strtolower($query->search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($search): bool {
                return array_any($row, fn($value) => $value !== null && str_contains(mb_strtolower((string) $value), $search));
            }));
        }

        $sort = $query->sort;
        $direction = $sort !== null && strtolower($sort->direction) === TableConstants::ORDER_DESC ? -1 : 1;
        if ($sort !== null) {
            usort($rows, static function (array $a, array $b) use ($sort, $direction, $keyField): int {
                $byValue = self::compareValues($a[$sort->field] ?? null, $b[$sort->field] ?? null, $direction);
                if ($byValue !== 0) {
                    return $byValue;
                }

                return $direction * self::compareKeys($a[$keyField] ?? null, $b[$keyField] ?? null);
            });
        }

        $totalCount = count($rows);
        $window = self::window($rows, $query, $keyField, $direction);
        $anchorFields = $sort === null || $sort->field === $keyField ? [$keyField] : [$sort->field, $keyField];

        return new TableSnapshotDTO(
            rows: $window,
            totalCount: $totalCount,
            limit: $query->limit,
            firstAnchor: $window === [] ? null : TableAnchorDTO::fromRow($window[0], $anchorFields),
            lastAnchor: $window === [] ? null : TableAnchorDTO::fromRow($window[count($window) - 1], $anchorFields),
        );
    }

    /**
     * Cuts the window the query asked for out of the ordered rows.
     *
     * @param list<array<string, mixed>> $rows Ordered rows of the whole filtered set
     * @param TableQueryDTO $query Query parameters
     * @param string $keyField Payload field the row key travels under
     * @param int $direction 1 ascending, -1 descending
     * @return list<array<string, mixed>> Rows of the window, in the set's own order
     */
    private static function window(array $rows, TableQueryDTO $query, string $keyField, int $direction): array
    {
        if ($query->limit === TableConstants::NO_LIMIT) {
            return $rows;
        }
        if ($query->pageIndex !== null) {
            return array_slice($rows, max(0, $query->pageIndex) * $query->limit, $query->limit);
        }

        $takesFromEnd = $query->anchorDirection === TableAnchorDirection::Before;
        if ($query->anchor === null) {
            return $takesFromEnd
                ? array_slice($rows, max(0, count($rows) - $query->limit), $query->limit)
                : array_slice($rows, 0, $query->limit);
        }

        $boundary = self::boundary($rows, $query->anchor, $query->sort, $keyField, $direction, $takesFromEnd);
        if (!$takesFromEnd) {
            return array_slice($rows, $boundary, $query->limit);
        }

        $start = max(0, $boundary - $query->limit);

        return array_slice($rows, $start, $boundary - $start);
    }

    /**
     * Walks the rows to the place the anchor names.
     *
     * @param list<array<string, mixed>> $rows Rows of the whole filtered set, in the set's own order
     * @param TableAnchorDTO $anchor Place the window is taken from
     * @param ?TableSortDTO $sort Ordering the window asked for, or null when the source ordered the rows
     * @param string $keyField Payload field the row key travels under
     * @param int $direction 1 ascending, -1 descending
     * @param bool $takesFromEnd Whether the window runs back from the anchor rather than on from it
     * @return int Index the window starts at, or ends before when it runs back
     */
    private static function boundary(
        array $rows,
        TableAnchorDTO $anchor,
        ?TableSortDTO $sort,
        string $keyField,
        int $direction,
        bool $takesFromEnd,
    ): int {
        if ($sort === null) {
            return self::keyBoundary($rows, $anchor, $keyField, $takesFromEnd);
        }

        foreach ($rows as $index => $row) {
            $comparison = self::compareToAnchor($row, $anchor, $sort, $keyField, $direction);
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
     * Places one row against the anchor, the same walk down the key the ordering makes.
     *
     * @param array<string, mixed> $row Row to place
     * @param TableAnchorDTO $anchor Place the window is taken from
     * @param TableSortDTO $sort Ordering the window asked for
     * @param string $keyField Payload field the row key travels under
     * @param int $direction 1 ascending, -1 descending
     * @return int Negative, zero or positive in the ordering's sense
     */
    private static function compareToAnchor(
        array $row,
        TableAnchorDTO $anchor,
        TableSortDTO $sort,
        string $keyField,
        int $direction,
    ): int {
        $byValue = self::compareValues($row[$sort->field] ?? null, $anchor->values[$sort->field] ?? null, $direction);
        if ($byValue !== 0) {
            return $byValue;
        }

        return $direction * self::compareKeys($row[$keyField] ?? null, $anchor->values[$keyField] ?? null);
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
