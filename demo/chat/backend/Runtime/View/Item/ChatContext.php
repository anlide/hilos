<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Hilos\Runtime\View\Item\RtItem;

/**
 * ChatContext Rt item - read-only wrapper for chat context state.
 *
 * @property-read ?string $topic
 * @property-read float $topicConfidence
 * @property-read string $summary
 */
final class ChatContext extends RtItem
{
    public const string ID_MAIN = StateChatContext::ID_MAIN;

    public function __construct(StateChatContext &$state)
    {
        parent::__construct($state);
    }

    public function __get(string $name): mixed
    {
        return $this->_state->{$name};
    }

    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
