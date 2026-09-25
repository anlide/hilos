<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\TableLagRuntime as StateTableLagRuntime;
use Hilos\Runtime\View\Actions\Item\TableLagRuntimeActions;

/**
 * Read-only view of the test-only table lag singleton (HIL-1020).
 *
 * The row says how long this node holds a table's changed window and its facet counts back. The
 * page router reads the first before it answers a viewport, and the browser context reads the
 * second before it counts facets; both read it on every check rather than once, so taking the lag
 * off lets a held frame go at the next worker tick. Writing goes through
 * {@see TableLagRuntimeActions}, because only the write path puts the change on the RT sync wire.
 *
 * @extends RtItem<StateTableLagRuntime>
 *
 * @property-read int $windowMs Milliseconds a changed table window is held back; 0 when off
 * @property-read int $facetsMs Milliseconds a facet count is held back; 0 when off
 * @property-read TableLagRuntimeActions $actions Write operations for the runtime singleton
 */
final class TableLagRuntime extends RtItem
{
    /**
     * @param StateTableLagRuntime $state Backing runtime state
     */
    public function __construct(StateTableLagRuntime $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return int|TableLagRuntimeActions Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): int|TableLagRuntimeActions
    {
        return match ($name) {
            StateTableLagRuntime::windowMs => $this->_state->windowMs,
            StateTableLagRuntime::facetsMs => $this->_state->facetsMs,
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
