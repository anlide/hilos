<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Runtime\State\Item\SuperfanAgentStatus as StateSuperfanAgentStatus;
use Demo\Chat\Runtime\View\Actions\Item\SuperfanAgentStatusActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\HilosException;

/**
 * Read-only wrapper over a superfan agent status runtime row.
 *
 * @extends RtItem<StateSuperfanAgentStatus>
 *
 * @property-read string $agentId Superfan agent identifier
 * @property-read string $status Superfan run status value
 * @property-read int $updatedAt Last update unix time
 * @property-read SuperfanAgentStatusActions $actions Write operations for this superfan status
 */
final class SuperfanAgentStatus extends RtItem
{
    /**
     * @param StateSuperfanAgentStatus $state Backing runtime state
     */
    public function __construct(StateSuperfanAgentStatus $state)
    {
        parent::__construct($state);
    }

    /**
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws HilosException When an inherited getter or an implementation's relation read fails
     */
    public function __get(string $name): int|string|SuperfanAgentStatusActions
    {
        return match ($name) {
            StateSuperfanAgentStatus::agentId => $this->_state->agentId,
            StateSuperfanAgentStatus::status => $this->_state->status,
            StateSuperfanAgentStatus::updatedAt => $this->_state->updatedAt,
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
