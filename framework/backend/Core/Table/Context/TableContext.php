<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Context;

use Hilos\Core\Table\Collection\TableMutationSignalCollection;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
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
     * Builds table mutation payloads for the routed tables that react to the source event.
     *
     * @param TableSourceEventDTO $event Source event being projected to tables
     * @param iterable<string> $tableKeys Table keys from the signal router declaration
     * @return TableMutationSignalCollection Table mutation payloads ready for WebSocket fan-out
     */
    public function buildMutationSignalsForSourceEvent(
        TableSourceEventDTO $event,
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

            $mutation = $table->buildMutationForSourceEvent($event);
            if ($mutation === null) {
                continue;
            }

            $signals->add(new TableMutationSignalData($tableKey, $mutation));
        }

        return $signals;
    }

    /**
     * Magic property access: Hilos::$table->users → TableDefinition.
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
