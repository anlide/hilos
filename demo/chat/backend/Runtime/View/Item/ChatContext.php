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

    /**
     * Create Rt item from chat context state.
     *
     * @param StateChatContext $state Chat context state (reference)
     */
    public function __construct(StateChatContext &$state)
    {
        parent::__construct($state);
    }

    /**
     * Get property value by name (topic, topicConfidence, summary).
     *
     * @param string $name Property name
     * @return mixed Property value
     */
    public function __get(string $name): mixed
    {
        return $this->_state->{$name};
    }

    /**
     * Convert state to array.
     *
     * @return array<string, mixed> State data (topic, topicConfidence, summary)
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
