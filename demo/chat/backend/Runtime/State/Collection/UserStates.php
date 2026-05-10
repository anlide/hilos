<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\ChatUserState;
use Hilos\Runtime\State\Collection\RtStates;
use OutOfBoundsException;

/**
 * UserStates - Per-user chat runtime state collection.
 *
 * @extends RtStates<ChatUserState>
 */
final class UserStates extends RtStates
{
    public const string STATE_CLASS = ChatUserState::class;

    /**
     * @param ?string $id User id, or null for a missing optional runtime key
     * @return ?ChatUserState User runtime state, or null when missing
     */
    public function get(?string $id): ?ChatUserState
    {
        /** @var ?ChatUserState $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use {@see get()} when absence is valid.
     *
     * @param mixed $offset User id
     * @return ChatUserState User runtime state
     */
    public function offsetGet(mixed $offset): ChatUserState
    {
        if ($offset === null) {
            throw new OutOfBoundsException('User runtime state not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("User runtime state not found: {$offset}");
    }
}
