<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\TableConstants;

/**
 * In-memory search, sort and pagination for table data sources
 * that load all rows into PHP memory.
 *
 * Data sources that can push these operations to SQL or another backend
 * should implement query() directly instead of using this helper.
 */
final class InMemoryTableFilter
{
    /**
     * Applies search, sort and pagination to an in-memory row set.
     *
     * @param array<int, array<string, mixed>> $rows All rows from the data source
     * @param TableQueryDTO $query Query parameters
     *
     * @return TableResultDTO Filtered/sorted/paginated result
     */
    public static function apply(array $rows, TableQueryDTO $query): TableResultDTO
    {
        if ($query->search !== '') {
            $search = mb_strtolower($query->search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($search): bool {
                return array_any($row, fn($value) => $value !== null && str_contains(mb_strtolower((string) $value), $search));
            }));
        }

        if ($query->orderBy !== '') {
            $dir = strtolower($query->orderDirection) === TableConstants::ORDER_DESC ? -1 : 1;
            usort($rows, static function (array $a, array $b) use ($query, $dir): int {
                $va = $a[$query->orderBy] ?? null;
                $vb = $b[$query->orderBy] ?? null;
                if ($va === null && $vb === null) return 0;
                if ($va === null) return $dir;
                if ($vb === null) return -$dir;
                return $dir * strnatcasecmp((string) $va, (string) $vb);
            });
        }

        $totalCount = count($rows);

        if ($query->limit !== TableConstants::NO_LIMIT) {
            $rows = array_slice($rows, $query->offset, $query->limit);
        } elseif ($query->offset > 0) {
            $rows = array_slice($rows, $query->offset);
        }

        return new TableResultDTO(
            rows: $rows,
            totalCount: $totalCount,
            offset: $query->offset,
            limit: $query->limit,
        );
    }
}
