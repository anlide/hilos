<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Runtime\State\Collection\BotAgentStatuses as StateBotAgentStatuses;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Actions\Collection\BotAgentStatusesActions;
use Demo\Chat\Runtime\View\Item\BotAgentStatus;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * Read-only wrapper around bot agent lifecycle statuses.
 *
 * @extends RtCollection<BotAgentStatus, BotAgentStatusesActions>
 * @property-read BotAgentStatusesActions $actions Actions for write operations
 */
final class BotAgentStatuses extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateBotAgentStatuses
    {
        /** @var StateBotAgentStatuses */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateBotAgentStatus instance
     * @return BotAgentStatus View item for this bot status
     */
    protected function createRtItem(RtState &$state): BotAgentStatus
    {
        /** @var StateBotAgentStatus $state */
        return new BotAgentStatus($state);
    }

    /**
     * @param mixed $offset Bot id
     * @return ?BotAgentStatus Item or null
     */
    public function offsetGet(mixed $offset): ?BotAgentStatus
    {
        /** @var ?BotAgentStatus $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return BotAgentStatusesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): BotAgentStatusesActions
    {
        /** @var BotAgentStatusesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    public function __get(string $name): BotAgentStatusesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
