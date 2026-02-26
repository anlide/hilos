<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\TableType;

/**
 * Data source for a table. One implementation per TableType (Entity, Sql, Other).
 *
 * Tables are stored in Hilos::$table (per-worker); access e.g. Hilos::$table->users->loadPage(N, M).
 *
 * @see TableType
 * @see EntityTableDataSource
 * @see SqlTableDataSource
 * @see OtherTableDataSource
 */
interface TableDataSourceInterface
{
    /**
     * Returns the source type (Entity, Sql, or Other).
     *
     * @return TableType
     */
    public function getType(): TableType;

    /**
     * Loads full dataset (e.g. for initial load or refresh_snapshot).
     *
     * @return array<int, array<string, mixed>> List of rows (assoc arrays, frontend-ready)
     */
    public function loadFull(): array;

    /**
     * Loads one page for server-side pagination.
     *
     * @param int $offset Zero-based offset
     * @param int $limit  Page size
     *
     * @return array<int, array<string, mixed>> Rows for this page
     */
    public function loadPage(int $offset, int $limit): array;

    /**
     * Returns total number of rows for pagination.
     *
     * @return int Row count, or -1 if unknown / not supported
     */
    public function getTotalCount(): int;
}
