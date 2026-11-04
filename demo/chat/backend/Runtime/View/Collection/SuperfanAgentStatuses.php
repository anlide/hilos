<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Runtime\State\Collection\SuperfanAgentStatuses as StateSuperfanAgentStatuses;
use Demo\Chat\Runtime\State\Item\SuperfanAgentStatus as StateSuperfanAgentStatus;
use Demo\Chat\Runtime\View\Actions\Collection\SuperfanAgentStatusesActions;
use Demo\Chat\Runtime\View\Item\SuperfanAgentStatus;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\HilosException;

/**
 * Read-only wrapper around superfan agent status runtime rows.
 *
 * @extends RtCollection<SuperfanAgentStatus, SuperfanAgentStatusesActions>
 * @property-read SuperfanAgentStatusesActions $actions Actions for write operations
 */
final class SuperfanAgentStatuses extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateSuperfanAgentStatuses
    {
        /** @var StateSuperfanAgentStatuses */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateSuperfanAgentStatus instance
     * @return SuperfanAgentStatus View item for this superfan status
     */
    protected function createRtItem(RtState $state): SuperfanAgentStatus
    {
        /** @var StateSuperfanAgentStatus $state */
        return new SuperfanAgentStatus($state);
    }

    /**
     * @param mixed $offset Superfan agent id
     * @return ?SuperfanAgentStatus Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?SuperfanAgentStatus
    {
        /** @var ?SuperfanAgentStatus $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return SuperfanAgentStatusesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): SuperfanAgentStatusesActions
    {
        /** @var SuperfanAgentStatusesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name to read
     * @return SuperfanAgentStatusesActions Superfan status actions
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException When the framework operation fails
     */
    public function __get(string $name): SuperfanAgentStatusesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
