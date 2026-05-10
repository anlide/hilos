<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\BotAgentStatus;
use Hilos\Runtime\State\Collection\RtStates;
use OutOfBoundsException;

/**
 * Runtime lifecycle statuses keyed by bot id.
 *
 * @extends RtStates<BotAgentStatus>
 */
final class BotAgentStatuses extends RtStates
{
    public const string STATE_CLASS = BotAgentStatus::class;

    /**
     * @param ?string $id Bot id, or null for a missing optional runtime key
     * @return ?BotAgentStatus Bot lifecycle status, or null when missing
     */
    public function get(?string $id): ?BotAgentStatus
    {
        /** @var ?BotAgentStatus $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use {@see get()} when absence is valid.
     *
     * @param mixed $offset Bot id
     * @return BotAgentStatus Bot lifecycle status
     */
    public function offsetGet(mixed $offset): BotAgentStatus
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Bot agent status not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Bot agent status not found: {$offset}");
    }
}
