<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Runtime\State\Collection\UserStates as StateUserStates;
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\View\Actions\Collection\UserStatesActions;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * UserStates - Read-only wrapper around per-user chat runtime states.
 *
 * @extends RtCollection<ChatUserState, UserStatesActions>
 * @property-read UserStatesActions $actions Actions for write operations
 */
final class UserStates extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateUserStates
    {
        /** @var StateUserStates */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateChatUserState instance
     * @return ChatUserState View item for this user state
     */
    protected function createRtItem(RtState &$state): ChatUserState
    {
        /** @var StateChatUserState $state */
        return new ChatUserState($state);
    }

    /**
     * @param mixed $offset User ID as string or int
     * @return ?ChatUserState Item or null
     */
    public function offsetGet(mixed $offset): ?ChatUserState
    {
        /** @var ?ChatUserState $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return ?ChatUserState First item or null
     */
    public function first(): ?ChatUserState
    {
        /** @var ?ChatUserState $item */
        $item = parent::first();

        return $item;
    }

    /**
     * @return ?ChatUserState Last item or null
     */
    public function last(): ?ChatUserState
    {
        /** @var ?ChatUserState $item */
        $item = parent::last();

        return $item;
    }

    /**
     * @return ?ChatUserState Current item or null
     */
    public function current(): ?ChatUserState
    {
        /** @var ?ChatUserState $item */
        $item = parent::current();

        return $item;
    }

    /**
     * @param string $key User ID as string
     * @return ?ChatUserState Item or null
     */
    protected function getRtItemForKey(string $key): ?ChatUserState
    {
        /** @var ?ChatUserState $item */
        $item = parent::getRtItemForKey($key);

        return $item;
    }

    /**
     * @return UserStatesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): UserStatesActions
    {
        /** @var UserStatesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    public function __get(string $name): UserStatesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
