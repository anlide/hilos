<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Item;

use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Exception\TablePropertyNotFoundException;

/**
 * Represents a single row in a table, accessed via ArrayAccess on TableDefinition.
 *
 * Usage: Hilos::$table->bots[$botId]->actions->delete()
 *
 * Not readonly: uses mutable $_actions for lazy loading of TableItemActions.
 *
 * @property-read TableItemActions $actions Item-level actions (update, delete, etc.)
 */
class TableItem
{
    private ?TableItemActions $_actions = null;

    /**
     * @param TableDefinition $definition Parent table definition
     * @param string|int $id Row identifier
     */
    public function __construct(
        private readonly TableDefinition $definition,
        private readonly string|int $id,
    ) {
    }

    /**
     * Returns the row identifier.
     */
    public function getId(): string|int
    {
        return $this->id;
    }

    /**
     * Magic getter for lazy-loaded properties (e.g. actions).
     *
     * @throws TablePropertyNotFoundException If the property does not exist
     */
    public function __get(string $name): mixed
    {
        if ($name === TableConstants::PROPERTY_ACTIONS) {
            return $this->getActions();
        }

        throw new TablePropertyNotFoundException($name);
    }

    /**
     * Magic isset for properties available via __get.
     */
    public function __isset(string $name): bool
    {
        return $name === TableConstants::PROPERTY_ACTIONS && $this->definition->getItemActionsClass() !== null;
    }

    /**
     * Lazily creates and returns the item actions instance.
     *
     * @throws TableActionsNotConfiguredException If item actions class is not configured
     */
    private function getActions(): TableItemActions
    {
        if ($this->_actions === null) {
            $class = $this->definition->getItemActionsClass();
            if ($class === null) {
                throw new TableActionsNotConfiguredException();
            }
            $this->_actions = new $class($this->definition, $this->id);
        }
        return $this->_actions;
    }
}
