<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Runtime\State\Item\GuardianAgentStatus as StateGuardianAgentStatus;
use Demo\Chat\Runtime\View\Actions\Item\GuardianAgentStatusActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only wrapper over a guardian agent status runtime row.
 *
 * @extends RtItem<StateGuardianAgentStatus>
 *
 * @property-read string $agentId Guardian agent identifier
 * @property-read string $status Guardian run status value
 * @property-read int $updatedAt Last update unix time
 * @property-read GuardianAgentStatusActions $actions Write operations for this guardian status
 */
final class GuardianAgentStatus extends RtItem
{
    /**
     * @param StateGuardianAgentStatus $state Backing runtime state
     */
    public function __construct(StateGuardianAgentStatus &$state)
    {
        parent::__construct($state);
    }

    /**
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): int|string|GuardianAgentStatusActions
    {
        return match ($name) {
            StateGuardianAgentStatus::agentId => $this->_state->agentId,
            StateGuardianAgentStatus::status => $this->_state->status,
            StateGuardianAgentStatus::updatedAt => $this->_state->updatedAt,
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
