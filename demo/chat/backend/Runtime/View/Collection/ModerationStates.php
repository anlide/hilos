<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Runtime\State\Collection\ModerationStates as StateModerationStates;
use Demo\Chat\Runtime\State\Item\ModerationState as StateModerationState;
use Demo\Chat\Runtime\View\Actions\ModerationStatesActions;
use Demo\Chat\Runtime\View\Item\ModerationState;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;

/**
 * ModerationStates Rt collection - read-only wrapper around moderation states.
 *
 * @extends RtCollection<ModerationState>
 * @property-read ModerationStatesActions $actions Actions for write operations
 */
class ModerationStates extends RtCollection
{
    /** @return StateModerationStates */
    public function getStateCollection(): StateModerationStates
    {
        /** @var StateModerationStates */
        return parent::getStateCollection();
    }

    /**
     * Create Rt item from state.
     *
     * @param RtState $state StateModerationState instance
     * @return RtItem ModerationState instance
     */
    protected function createRtItem(RtState &$state): RtItem
    {
        /** @var StateModerationState $state */
        return new ModerationState($state);
    }

    public function offsetGet(mixed $offset): ?ModerationState
    {
        return parent::offsetGet($offset);
    }

    public function first(): ?ModerationState
    {
        return parent::first();
    }

    public function last(): ?ModerationState
    {
        return parent::last();
    }

    public function current(): ?ModerationState
    {
        return parent::current();
    }

    protected function getRtItemForKey(string $key): ?ModerationState
    {
        return parent::getRtItemForKey($key);
    }

    protected function getActions(): ModerationStatesActions
    {
        return parent::getActions();
    }

    public function __get(string $name): ModerationStatesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
