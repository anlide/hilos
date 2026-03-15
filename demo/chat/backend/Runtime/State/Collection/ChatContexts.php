<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\ChatContext;
use Hilos\Runtime\State\Collection\RtStates;

/**
 * ChatContexts state collection - singleton chat context for bot context analysis.
 *
 * @extends RtStates<ChatContext>
 */
final class ChatContexts extends RtStates
{
    public const string STATE_CLASS = ChatContext::class;
}
