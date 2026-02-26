<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\TableType;

/**
 * Table data source backed by raw SQL.
 *
 * Structure only: no SQL execution implemented yet.
 * When implemented: constructor would accept connection + query builder or safe query descriptor.
 */
abstract class SqlTableDataSource implements TableDataSourceInterface
{
    /**
     * Returns the source type (Entity, Sql, or Other).
     *
     * @return TableType
     */
    public function getType(): TableType
    {
        return TableType::Sql;
    }

    /**
     * Loads full dataset (e.g. for initial load or refresh_snapshot).
     *
     * @return array<int, array<string, mixed>> List of rows (assoc arrays, frontend-ready)
     */
    public function loadFull(): array
    {
        return [];
    }

    /**
     * Loads one page for server-side pagination.
     *
     * @param int $offset Zero-based offset
     * @param int $limit  Page size
     *
     * @return array<int, array<string, mixed>> Rows for this page
     */
    public function loadPage(int $offset, int $limit): array
    {
        return [];
    }

    /**
     * Returns total number of rows for pagination.
     *
     * @return int Row count, or -1 if unknown / not supported
     */
    public function getTotalCount(): int
    {
        return -1;
    }
}
