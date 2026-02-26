<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Context;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Exception\TableNotFoundException;

/**
 * Base table context — analogous to DbContext / RtContext.
 *
 * Holds named TableDefinition instances. Subclass in your app to register tables.
 *
 * Usage: Hilos::$table->users (via __get)
 */
abstract class TableContext
{
    /** @var array<string, TableDefinition> */
    protected array $_tables = [];

    /**
     * Registers tables. Called during Hilos::init().
     */
    abstract public function configure(): void;

    /**
     * Registers a table definition by name.
     *
     * @param string $name Table key (e.g. users, bots)
     * @param TableDefinition $definition Table definition instance
     */
    protected function register(string $name, TableDefinition $definition): void
    {
        $this->_tables[$name] = $definition;
    }

    /**
     * Returns a table definition by name.
     *
     * @param string $name Table key
     *
     * @return TableDefinition|null Definition or null if not found
     */
    public function get(string $name): ?TableDefinition
    {
        return $this->_tables[$name] ?? null;
    }

    /**
     * Magic property access: Hilos::$table->users → TableDefinition
     *
     * @param string $name Table key
     *
     * @return TableDefinition
     *
     * @throws TableNotFoundException When table does not exist
     */
    public function __get(string $name): TableDefinition
    {
        if (!isset($this->_tables[$name])) {
            throw new TableNotFoundException("Table [{$name}] does not exist in Hilos::\$table");
        }
        return $this->_tables[$name];
    }

    /**
     * Checks if a table is registered.
     *
     * @param string $name Table key
     *
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->_tables[$name]);
    }
}
