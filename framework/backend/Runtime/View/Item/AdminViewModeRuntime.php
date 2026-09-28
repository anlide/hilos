<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\AdminViewModeRuntime as StateAdminViewModeRuntime;
use Hilos\Runtime\View\Actions\Item\AdminViewModeRuntimeActions;

/**
 * Read-only view of the admin view mode singleton of a node (HIL-1249).
 *
 * The row says whether a non-admin may open the admin section on this node to look. Writing goes
 * through {@see AdminViewModeRuntimeActions}, because only the write path puts the change on the
 * RT sync wire.
 *
 * @extends RtItem<StateAdminViewModeRuntime>
 *
 * @property-read bool $enabled Whether a non-admin may open the admin section on this node to look
 * @property-read AdminViewModeRuntimeActions $actions Write operations for the runtime singleton
 */
final class AdminViewModeRuntime extends RtItem
{
    /**
     * @param StateAdminViewModeRuntime $state Backing runtime state
     */
    public function __construct(StateAdminViewModeRuntime $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return bool|AdminViewModeRuntimeActions Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws HilosException When an inherited getter or an implementation's relation read fails
     */
    public function __get(string $name): bool|AdminViewModeRuntimeActions
    {
        return match ($name) {
            StateAdminViewModeRuntime::enabled => $this->_state->enabled,
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
