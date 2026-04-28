<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Runtime\State\Item\GuardianAgentStatus as StateGuardianAgentStatus;
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
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): int|string
    {
        /** @var StateGuardianAgentStatus $state */
        $state = $this->_state;

        return match ($name) {
            StateGuardianAgentStatus::agentId => $state->agentId,
            StateGuardianAgentStatus::status => $state->status,
            StateGuardianAgentStatus::updatedAt => $state->updatedAt,
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        /** @var StateGuardianAgentStatus $state */
        $state = $this->_state;

        return $state->toArray();
    }
}
