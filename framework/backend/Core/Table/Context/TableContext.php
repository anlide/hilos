<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Context;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Collection\TableMutationSignalCollection;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Exception\TableNotFoundException;

/**
 * Registry for application table definitions.
 *
 * Subclasses register named TableDefinition instances during configuration.
 * Magic property access exposes registered tables as `Hilos::$table->users`.
 */
abstract class TableContext
{
    /** @var array<string, TableDefinition> Map of table name to definition */
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
     * @return ?TableDefinition Definition or null if not found
     */
    public function get(string $name): ?TableDefinition
    {
        return $this->_tables[$name] ?? null;
    }

    /**
     * Builds table mutation payloads for the routed tables that react to the source change.
     *
     * @param SourceChange $change DB/RT source change being applied to tables
     * @param iterable<string> $tableKeys Table keys from the source-change routing declaration
     * @return TableMutationSignalCollection Table mutation payloads ready for WebSocket fan-out
     */
    public function buildMutationSignalsForSourceEvent(
        SourceChange $change,
        iterable $tableKeys,
    ): TableMutationSignalCollection {
        $signals = new TableMutationSignalCollection();

        foreach ($tableKeys as $tableKey) {
            if (!is_string($tableKey) || $tableKey === '') {
                continue;
            }

            $table = $this->get($tableKey);
            if ($table === null) {
                continue;
            }

            $mutation = $table->buildMutationForSourceEvent($change);
            if ($mutation === null) {
                continue;
            }

            $signals->add(new TableMutationSignalData($tableKey, $mutation));
        }

        return $signals;
    }

    /**
     * Resolves a registered table through magic property access.
     *
     * @param string $name Table key
     * @return TableDefinition Table definition instance
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
     * @return bool True if table exists
     */
    public function __isset(string $name): bool
    {
        return isset($this->_tables[$name]);
    }
}
