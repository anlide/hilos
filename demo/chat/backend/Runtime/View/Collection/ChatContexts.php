<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Runtime\State\Collection\ChatContexts as StateChatContexts;
use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\Actions\ChatContextsActions;
use Demo\Chat\Runtime\View\Item\ChatContext;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
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
final class ChatContexts extends RtCollection
{
    /**
     * Get underlying state collection.
     *
     * @return StateChatContexts State collection
     * @throws RtActionsStateCollectionNullException If state collection is null (should not happen for properly initialized collection)
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
        /** @var ?ChatContext $item */
        $item = parent::offsetGet($offset);
        return $item;
    }

    /**
     * Get first item in collection.
     *
     * @return ?ChatContext First item or null
     */
    public function first(): ?ChatContext
    {
        /** @var ?ChatContext $item */
        $item = parent::first();
        return $item;
    }

    /**
     * Get last item in collection.
     *
     * @return ?ChatContext Last item or null
     */
    public function last(): ?ChatContext
    {
        /** @var ?ChatContext $item */
        $item = parent::last();
        return $item;
    }

    /**
     * Get current item in iteration.
     *
     * @return ?ChatContext Current item or null
     */
    public function current(): ?ChatContext
    {
        /** @var ?ChatContext $item */
        $item = parent::current();
        return $item;
    }

    /**
     * Get item by key.
     *
     * @param string $key Context key
     * @return ?ChatContext Item or null
     */
    protected function getRtItemForKey(string $key): ?ChatContext
    {
        /** @var ?ChatContext $item */
        $item = parent::getRtItemForKey($key);
        return $item;
    }

    /**
     * Returns actions instance for write operations.
     *
     * @return ChatContextsActions Actions instance
     * @throws RtCollectionActionsClassException If actions class is not of type ChatContextsActions (should not happen for properly initialized collection)
     */
    protected function getActions(): ChatContextsActions
    {
        /** @var ChatContextsActions $actions */
        $actions = parent::getActions();
        return $actions;
    }

    /**
     * Magic getter for actions property.
     *
     * @param string $name Property name (actions)
     * @return ChatContextsActions Actions instance
     * @throws RtCollectionActionsClassException If actions class is not of type ChatContextsActions (should not happen for properly initialized collection)
     * @throws RtCollectionPropertyNotFoundException If property name is not "actions"
     */
    public function __get(string $name): ChatContextsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
