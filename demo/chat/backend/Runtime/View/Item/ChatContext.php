<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\Actions\Item\ChatContextActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * ChatContext - Read-only wrapper for chat context state.
 *
 * @extends RtItem<StateChatContext>
 *
 * @property-read ?string $topic
 * @property-read float $topicConfidence
 * @property-read string $summary
 * @property-read ChatContextActions $actions Write operations for this chat context
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
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): mixed
    {
        /** @var StateChatContext $state */
        $state = $this->_state;

        return match ($name) {
            StateChatContext::topic => $state->topic,
            StateChatContext::topicConfidence => $state->topicConfidence,
            StateChatContext::summary => $state->summary,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
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
