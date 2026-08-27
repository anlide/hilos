<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Runtime\State\Collection\GuardianAgentStatuses as StateGuardianAgentStatuses;
use Demo\Chat\Runtime\State\Item\GuardianAgentStatus as StateGuardianAgentStatus;
use Demo\Chat\Runtime\View\Actions\Collection\GuardianAgentStatusesActions;
use Demo\Chat\Runtime\View\Item\GuardianAgentStatus;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * Read-only wrapper around guardian agent status runtime rows.
 *
 * @extends RtCollection<GuardianAgentStatus, GuardianAgentStatusesActions>
 * @property-read GuardianAgentStatusesActions $actions Actions for write operations
 */
final class GuardianAgentStatuses extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateGuardianAgentStatuses
    {
        /** @var StateGuardianAgentStatuses */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateGuardianAgentStatus instance
     * @return GuardianAgentStatus View item for this guardian status
     */
    protected function createRtItem(RtState $state): GuardianAgentStatus
    {
        /** @var StateGuardianAgentStatus $state */
        return new GuardianAgentStatus($state);
    }

    /**
     * @param mixed $offset Guardian agent id
     * @return ?GuardianAgentStatus Item or null
     */
    public function offsetGet(mixed $offset): ?GuardianAgentStatus
    {
        /** @var ?GuardianAgentStatus $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return GuardianAgentStatusesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): GuardianAgentStatusesActions
    {
        /** @var GuardianAgentStatusesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    public function __get(string $name): GuardianAgentStatusesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
