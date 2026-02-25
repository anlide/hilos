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
     * Register tables. Called during Hilos::init().
     */
    abstract public function configure(): void;

    /**
     * Register a table definition by name.
     */
    protected function register(string $name, TableDefinition $definition): void
    {
        $this->_tables[$name] = $definition;
    }

    /**
     * Get a table definition by name (returns null if not found).
     */
    public function get(string $name): ?TableDefinition
    {
        return $this->_tables[$name] ?? null;
    }

    /**
     * Magic property access: Hilos::$table->users → TableDefinition
     *
     * @throws TableNotFoundException
     */
    public function __get(string $name): TableDefinition
    {
        if (!isset($this->_tables[$name])) {
            throw new TableNotFoundException("Table [{$name}] does not exist in Hilos::\$table");
        }
        return $this->_tables[$name];
    }

    public function __isset(string $name): bool
    {
        return isset($this->_tables[$name]);
    }
}
