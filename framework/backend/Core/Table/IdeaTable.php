<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Exception\Hilos\Table\TableNotFoundException;

/**
 * Table layer for Hilos. Holds named table data sources (Entity / Sql / Other).
 *
 * Stored in Hilos::$table (per worker). Access: Hilos::$table->users->loadPage(N, M).
 *
 * Child Hilos context (e.g. Demo) overrides createTable(), instantiates table hub,
 * registers tables via register(), returns instance.
 */
class IdeaTable
{
    /** @var array<string, TableDataSourceInterface> */
    private array $_tables = [];

    /**
     * Register a table data source by name.
     */
    public function register(string $name, TableDataSourceInterface $dataSource): void
    {
        $this->_tables[$name] = $dataSource;
    }

    /**
     * Get table data source by name (for variable key, e.g. in builders).
     */
    public function get(string $name): ?TableDataSourceInterface
    {
        return $this->_tables[$name] ?? null;
    }

    /**
     * Magic access: Hilos::$table->users -> TableDataSourceInterface
     *
     * @throws TableNotFoundException If table does not exist
     */
    public function __get(string $name): TableDataSourceInterface
    {
        if (!isset($this->_tables[$name])) {
            throw new TableNotFoundException("Table [{$name}] does not exist in Hilos::\$table");
        }
        return $this->_tables[$name];
    }
}
