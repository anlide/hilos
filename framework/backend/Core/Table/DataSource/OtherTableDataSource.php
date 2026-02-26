<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\TableType;

/**
 * Table data source for "other" type (e.g. list of backups corresponding to real files).
 *
 * Abstract: project implements loadFull/loadPage/getTotalCount.
 */
abstract class OtherTableDataSource implements TableDataSourceInterface
{
    /**
     * Returns the source type (Entity, Sql, or Other).
     *
     * @return TableType
     */
    public function getType(): TableType
    {
        return TableType::Other;
    }

    /**
     * Loads full dataset (e.g. for initial load or refresh_snapshot).
     *
     * @return array<int, array<string, mixed>> List of rows (assoc arrays, frontend-ready)
     */
    abstract public function loadFull(): array;

    /**
     * Loads one page for server-side pagination.
     *
     * @param int $offset Zero-based offset
     * @param int $limit  Page size
     *
     * @return array<int, array<string, mixed>> Rows for this page
     */
    abstract public function loadPage(int $offset, int $limit): array;

    /**
     * Returns total number of rows for pagination.
     *
     * @return int Row count, or -1 if unknown / not supported
     */
    abstract public function getTotalCount(): int;
}
