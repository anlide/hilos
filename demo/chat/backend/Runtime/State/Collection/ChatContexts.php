<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\ChatContext;
use Hilos\Runtime\State\Collection\RtStates;
use OutOfBoundsException;

/**
 * ChatContexts - Singleton chat context collection for bot context analysis.
 *
 * @extends RtStates<ChatContext>
 */
final class ChatContexts extends RtStates
{
    public const string STATE_CLASS = ChatContext::class;

    /**
     * @param string $id Context id
     * @return ?ChatContext Chat context state, or null when missing
     */
    public function get(string $id): ?ChatContext
    {
        /** @var ?ChatContext $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use {@see get()} when absence is valid.
     *
     * @param mixed $offset Context id
     * @return ChatContext Chat context state
     */
    public function offsetGet(mixed $offset): ChatContext
    {
        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Chat context state not found: {$offset}");
    }
}
