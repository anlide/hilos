<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Runtime\State\Collection\ChatContexts as StateChatContexts;
use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\Actions\ChatContextsActions;
use Demo\Chat\Runtime\View\Item\ChatContext;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;

/**
 * ChatContexts Rt collection - read-only wrapper around chat context state.
 *
 * Singleton collection (key "main") for shared chat context used by BotAgents.
 *
 * @extends RtCollection<ChatContext>
 * @property-read ChatContextsActions $actions Actions for write operations
 */
class ChatContexts extends RtCollection
{
    /**
     * Get underlying state collection.
     *
     * @return StateChatContexts State collection
     */
    public function getStateCollection(): StateChatContexts
    {
        /** @var StateChatContexts */
        return parent::getStateCollection();
    }

    /**
     * Create Rt item from state.
     *
     * @param RtState $state StateChatContext instance
     * @return RtItem ChatContext instance
     */
    protected function createRtItem(RtState &$state): RtItem
    {
        /** @var StateChatContext $state */
        return new ChatContext($state);
    }

    /**
     * Get item by offset (context key, e.g. "main").
     *
     * @param mixed $offset Context key
     * @return ?ChatContext Item or null
     */
    public function offsetGet(mixed $offset): ?ChatContext
    {
        return parent::offsetGet($offset);
    }

    /**
     * @return ?ChatContext First item or null
     */
    public function first(): ?ChatContext
    {
        return parent::first();
    }

    /**
     * @return ?ChatContext Last item or null
     */
    public function last(): ?ChatContext
    {
        return parent::last();
    }

    /**
     * @return ?ChatContext Current item or null
     */
    public function current(): ?ChatContext
    {
        return parent::current();
    }

    /**
     * Get item by key.
     *
     * @param string $key Context key
     * @return ?ChatContext Item or null
     */
    protected function getRtItemForKey(string $key): ?ChatContext
    {
        return parent::getRtItemForKey($key);
    }

    protected function getActions(): ChatContextsActions
    {
        return parent::getActions();
    }

    public function __get(string $name): ChatContextsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
