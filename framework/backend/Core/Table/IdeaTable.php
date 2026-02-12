<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Exception\Table\IdeaTableNotFoundException;

/**
 * Table layer for Idea. Holds named table data sources (Entity / Sql / Other).
 *
 * Stored in Idea::$table (per worker). Access: Idea::$table->users->loadPage(N, M).
 *
 * Child Idea (e.g. Demo) overrides createTable(), instantiates IdeaTable,
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
     * Magic access: Idea::$table->users -> TableDataSourceInterface
     *
     * @throws IdeaTableNotFoundException If table does not exist
     */
    public function __get(string $name): TableDataSourceInterface
    {
        if (!isset($this->_tables[$name])) {
            throw new IdeaTableNotFoundException("Table [{$name}] does not exist in Idea::\$table");
        }
        return $this->_tables[$name];
    }
}
