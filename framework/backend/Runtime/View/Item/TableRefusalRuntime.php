<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\TableRefusalRuntime as StateTableRefusalRuntime;
use Hilos\Runtime\View\Actions\Item\TableRefusalRuntimeActions;

/**
 * Read-only view of the test-only table refusal singleton (HIL-1131).
 *
 * The row names the one table whose windows this node refuses to build. The browser context reads
 * it inside the trap of every window build rather than once, so taking the refusal off lets the
 * very next window through. Writing goes through {@see TableRefusalRuntimeActions}, because only
 * the write path puts the change on the RT sync wire.
 *
 * @extends RtItem<StateTableRefusalRuntime>
 *
 * @property-read string $tableKey Wire key of the table whose windows are refused; empty when off
 * @property-read TableRefusalRuntimeActions $actions Write operations for the runtime singleton
 */
final class TableRefusalRuntime extends RtItem
{
    /**
     * @param StateTableRefusalRuntime $state Backing runtime state
     */
    public function __construct(StateTableRefusalRuntime $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|TableRefusalRuntimeActions Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|TableRefusalRuntimeActions
    {
        return match ($name) {
            StateTableRefusalRuntime::tableKey => $this->_state->tableKey,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
