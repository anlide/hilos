<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\Actions\Item\ChatContextActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only runtime item for the shared chat context state.
 *
 * @extends RtItem<StateChatContext>
 *
 * @property-read ?string $topic
 * @property-read float $topicConfidence
 * @property-read ?string $summary
 * @property-read ChatContextActions $actions Write operations for this chat context
 */
final class ChatContext extends RtItem
{
    public const string ID_MAIN = StateChatContext::ID_MAIN;

    /**
     * @param StateChatContext $state Chat context state
     */
    public function __construct(StateChatContext $state)
    {
        parent::__construct($state);
    }

    /**
     * Delegates known keys to the backing state.
     *
     * @param string $name Property name
     * @return ?string|float|ChatContextActions Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            StateChatContext::topic => $this->_state->topic,
            StateChatContext::topicConfidence => $this->_state->topicConfidence,
            StateChatContext::summary => $this->_state->summary,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> State data (topic, topicConfidence, summary)
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
