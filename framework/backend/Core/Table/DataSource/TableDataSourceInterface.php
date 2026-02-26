<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\TableType;

/**
 * Data source for a table. One implementation per TableType (Entity, Sql, Other).
 *
 * Tables are stored in Hilos::$table (per-worker); access e.g. Hilos::$table->users->query(...).
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
     * Executes a table query with search, sort and pagination.
     *
     * @param TableQueryDTO $query Query parameters
     *
     * @return TableResultDTO
     */
    public function query(TableQueryDTO $query): TableResultDTO;
}
