<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
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
     * Applies search, sort and pagination to an in-memory row set.
     *
     * The sort is settled by the row key: a sorted field with repeats orders the rows that
     * share a value arbitrarily, and two neighbouring pages sliced out of two such orderings
     * can show one row twice and another not at all. The key field is asked of the caller
     * rather than guessed, and it is required rather than optional so that a new table cannot
     * lose its tie-breaker without saying so — {@see TableDefinition::filterInMemory()} is
     * where a table gets it from its own row class.
     *
     * @param list<array<string, mixed>> $rows All rows to filter
     * @param TableQueryDTO $query Query parameters
     * @param string $keyField Payload field the row key travels under, used to settle the sort
     * @return TableSnapshotDTO Filtered/sorted/paginated snapshot
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
        if ($sort !== null) {
            $dir = strtolower($sort->direction) === TableConstants::ORDER_DESC ? -1 : 1;
            usort($rows, static function (array $a, array $b) use ($sort, $dir, $keyField): int {
                $byValue = self::compareValues($a[$sort->field] ?? null, $b[$sort->field] ?? null, $dir);
                if ($byValue !== 0) {
                    return $byValue;
                }

                return $dir * self::compareKeys($a[$keyField] ?? null, $b[$keyField] ?? null);
            });
        }

        $totalCount = count($rows);

        if ($query->limit !== TableConstants::NO_LIMIT) {
            $rows = array_slice($rows, $query->offset, $query->limit);
        } elseif ($query->offset > 0) {
            $rows = array_slice($rows, $query->offset);
        }

        return new TableSnapshotDTO(
            rows: $rows,
            totalCount: $totalCount,
            offset: $query->offset,
            limit: $query->limit,
        );
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
