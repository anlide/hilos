<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\SuperfanAgentStatus;
use Hilos\Runtime\State\Collection\RtStates;
use OutOfBoundsException;

/**
 * Runtime superfan run statuses keyed by superfan agent id.
 *
 * @extends RtStates<SuperfanAgentStatus>
 */
final class SuperfanAgentStatuses extends RtStates
{
    public const string STATE_CLASS = SuperfanAgentStatus::class;

    /**
     * @param ?string $id Superfan agent id, or null for a missing optional runtime key
     * @return ?SuperfanAgentStatus Superfan status, or null when missing
     */
    public function get(?string $id): ?SuperfanAgentStatus
    {
        /** @var ?SuperfanAgentStatus $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Superfan agent id
     * @return SuperfanAgentStatus Superfan status
     * @throws OutOfBoundsException When the requested state row is absent
     */
    public function offsetGet(mixed $offset): SuperfanAgentStatus
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Superfan agent status not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Superfan agent status not found: {$offset}");
    }
}
