<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DataSource;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\TableType;

/**
 * Table data source backed by raw SQL.
 *
 * Subclasses implement query() to build SQL with WHERE/ORDER BY/LIMIT/OFFSET.
 */
abstract class SqlTableDataSource implements TableDataSourceInterface
{
    /**
     * Returns the source type (Entity, Sql, or Other).
     *
     * @return TableType Data source type (Entity, Sql, or Other)
     */
    public function getType(): TableType
    {
        return TableType::Sql;
    }

    /**
     * Executes a table query with search, sort and pagination.
     * Subclasses should build SQL with WHERE/ORDER BY/LIMIT/OFFSET.
     *
     * @param TableQueryDTO $query Query parameters
     * @return TableResultDTO Query result with rows and total count
     */
    abstract public function query(TableQueryDTO $query): TableResultDTO;
}
