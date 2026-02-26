<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\TableType;

/**
 * Table data source for "other" type (e.g. list of backups corresponding to real files).
 *
 * Subclasses implement loadAll() to provide raw data.
 * Default query() uses loadAll() + InMemoryTableFilter; subclasses may override query() directly.
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
     * Executes a table query with search, sort and pagination.
     * Default: loads all rows via loadAll() and filters in memory.
     * Subclasses may override for more efficient implementations.
     *
     * @param TableQueryDTO $query Query parameters
     *
     * @return TableResultDTO
     */
    public function query(TableQueryDTO $query): TableResultDTO
    {
        return InMemoryTableFilter::apply($this->loadAll(), $query);
    }

    /**
     * Loads all rows from the underlying data source.
     * Subclasses must implement to provide raw data for in-memory filtering.
     *
     * @return array<int, array<string, mixed>> List of rows (assoc arrays, frontend-ready)
     */
    abstract protected function loadAll(): array;
}
