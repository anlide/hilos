<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\TableType;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Table data source backed by a DbCollection (Entity type).
 *
 * Example: Hilos::$table->register(Hilos::users, new EntityTableDataSource(Hilos::$db->users)).
 */
readonly class EntityTableDataSource implements TableDataSourceInterface
{
    /**
     * @param DbCollection $collection Db collection (e.g. Hilos::$db->users)
     */
    public function __construct(
        private DbCollection $collection,
    ) {
    }

    /**
     * Returns the source type (Entity, Sql, or Other).
     *
     * @return TableType
     */
    public function getType(): TableType
    {
        return TableType::Entity;
    }

    /**
     * Loads full dataset (e.g. for initial load or refresh_snapshot).
     *
     * @return array<int, array<string, mixed>> List of rows (assoc arrays, frontend-ready)
     */
    public function loadFull(): array
    {
        return $this->collection->toArray(idAsIndex: false, toFrontend: true);
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
        $all = $this->loadFull();
        return array_slice($all, $offset, $limit);
    }

    /**
     * Returns total number of rows for pagination.
     *
     * @return int Row count, or -1 if unknown / not supported
     */
    public function getTotalCount(): int
    {
        return count($this->collection);
    }
}
