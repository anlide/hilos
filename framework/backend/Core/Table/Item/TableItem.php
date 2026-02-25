<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Item;

use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Exception\TablePropertyNotFoundException;

/**
 * Represents a single row in a table, accessed via ArrayAccess on TableDefinition.
 *
 * Usage: Hilos::$table->bots[$botId]->actions->delete()
 */
class TableItem
{
    private ?TableItemActions $_actions = null;

    public function __construct(
        private readonly TableDefinition $definition,
        private readonly string|int $id,
    ) {
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    /**
     * @return TableItemActions
     */
    public function __get(string $name): mixed
    {
        if ($name === 'actions') {
            return $this->getActions();
        }

        throw new TablePropertyNotFoundException($name);
    }

    public function __isset(string $name): bool
    {
        return $name === 'actions' && $this->definition->getItemActionsClass() !== null;
    }

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
