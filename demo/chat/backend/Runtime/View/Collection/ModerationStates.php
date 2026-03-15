<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Runtime\State\Collection\ModerationStates as StateModerationStates;
use Demo\Chat\Runtime\State\Item\ModerationState as StateModerationState;
use Demo\Chat\Runtime\View\Actions\ModerationStatesActions;
use Demo\Chat\Runtime\View\Item\ModerationState;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;

/**
 * ModerationStates Rt collection - read-only wrapper around moderation states.
 *
 * @extends RtCollection<ModerationState>
 * @property-read ModerationStatesActions $actions Actions for write operations
 */
final class ModerationStates extends RtCollection
{
    /**
     * Get underlying state collection.
     *
     * @return StateModerationStates State collection instance
     * @throws RtActionsStateCollectionNullException If actions state collection is null
     */
    public function getStateCollection(): StateModerationStates
    {
        /** @var StateModerationStates */
        return parent::getStateCollection();
    }

    /**
     * Create Rt item from state.
     *
     * @param RtState $state StateModerationState instance (passed by reference)
     * @return RtItem ModerationState Rt item instance
     */
    protected function createRtItem(RtState &$state): RtItem
    {
        /** @var StateModerationState $state */
        return new ModerationState($state);
    }

    /**
     * Get moderation state by offset (user ID as string).
     *
     * @param mixed $offset User ID as string
     * @return ?ModerationState Moderation state or null if not found
     */
    public function offsetGet(mixed $offset): ?ModerationState
    {
        return parent::offsetGet($offset);
    }

    /**
     * Get first moderation state in collection.
     *
     * @return ?ModerationState First state or null if empty
     */
    public function first(): ?ModerationState
    {
        return parent::first();
    }

    /**
     * Get last moderation state in collection.
     *
     * @return ?ModerationState Last state or null if empty
     */
    public function last(): ?ModerationState
    {
        return parent::last();
    }

    /**
     * Get current moderation state in iteration.
     *
     * @return ?ModerationState Current state or null
     */
    public function current(): ?ModerationState
    {
        return parent::current();
    }

    /**
     * Get moderation state by key (user ID).
     *
     * @param string $key User ID as string
     * @return ?ModerationState Moderation state or null if not found
     */
    protected function getRtItemForKey(string $key): ?ModerationState
    {
        return parent::getRtItemForKey($key);
    }

    /**
     * Get moderation states actions instance.
     *
     * @return ModerationStatesActions Actions for write operations
     */
    protected function getActions(): ModerationStatesActions
    {
        return parent::getActions();
    }

    /**
     * Property getter (actions).
     *
     * @param string $name Property name (actions)
     * @return ModerationStatesActions Actions for write operations
     * @throws RtCollectionPropertyNotFoundException If property not found
     */
    public function __get(string $name): ModerationStatesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
