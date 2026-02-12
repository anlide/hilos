<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\TableType;

/**
 * Data source for a table. One implementation per TableType (Entity, Sql, Other).
 *
 * Tables are stored in Idea::$table (per-worker); access e.g. Idea::$table->users->loadPage(N, M).
 */
interface TableDataSourceInterface
{
    /**
     * Source type (entity / sql / other).
     */
    public function getType(): TableType;

    /**
     * Whether this table supports snapshot semantics (first load = snapshot, changes as pending, refresh = new snapshot).
     * Entity: typically true. Sql: false. Other: depends.
     */
    public function supportsSnapshot(): bool;

    /**
     * Load full dataset (e.g. for initial load or refresh_snapshot).
     *
     * @return array<int, array<string, mixed>> List of rows (assoc arrays, frontend-ready).
     */
    public function loadFull(): array;

    /**
     * Load one page for server-side pagination.
     *
     * @param int $offset Zero-based offset
     * @param int $limit  Page size
     * @return array<int, array<string, mixed>> Rows for this page
     */
    public function loadPage(int $offset, int $limit): array;

    /**
     * Total number of rows (for pagination). -1 if unknown / not supported.
     */
    public function getTotalCount(): int;
}
