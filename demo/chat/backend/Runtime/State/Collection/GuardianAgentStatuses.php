<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\GuardianAgentStatus;
use Hilos\Runtime\State\Collection\RtStates;
use OutOfBoundsException;

/**
 * Runtime guardian run statuses keyed by guardian agent id.
 *
 * @extends RtStates<GuardianAgentStatus>
 */
final class GuardianAgentStatuses extends RtStates
{
    public const string STATE_CLASS = GuardianAgentStatus::class;

    /**
     * @param ?string $id Guardian agent id, or null for a missing optional runtime key
     * @return ?GuardianAgentStatus Guardian status, or null when missing
     */
    public function get(?string $id): ?GuardianAgentStatus
    {
        /** @var ?GuardianAgentStatus $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use {@see get()} when absence is valid.
     *
     * @param mixed $offset Guardian agent id
     * @return GuardianAgentStatus Guardian status
     */
    public function offsetGet(mixed $offset): GuardianAgentStatus
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Guardian agent status not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Guardian agent status not found: {$offset}");
    }
}
