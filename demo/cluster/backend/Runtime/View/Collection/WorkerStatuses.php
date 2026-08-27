<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Collection;

use Demo\Cluster\Runtime\State\Collection\WorkerStatuses as StateWorkerStatuses;
use Demo\Cluster\Runtime\State\Item\WorkerStatus as StateWorkerStatus;
use Demo\Cluster\Runtime\View\Actions\Collection\WorkerStatusesActions;
use Demo\Cluster\Runtime\View\Item\WorkerStatus;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * Read-only wrapper around the fleet worker statuses.
 *
 * @extends RtCollection<WorkerStatus, WorkerStatusesActions>
 * @property-read WorkerStatusesActions $actions Actions for write operations
 */
final class WorkerStatuses extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateWorkerStatuses
    {
        /** @var StateWorkerStatuses */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateWorkerStatus instance
     * @return WorkerStatus View item for this worker status
     */
    protected function createRtItem(RtState $state): WorkerStatus
    {
        /** @var StateWorkerStatus $state */
        return new WorkerStatus($state);
    }

    /**
     * @param mixed $offset Fleet member index
     * @return ?WorkerStatus Item or null
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?WorkerStatus
    {
        /** @var ?WorkerStatus $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return WorkerStatusesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): WorkerStatusesActions
    {
        /** @var WorkerStatusesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever reading another declared property of the parent raises
     */
    public function __get(string $name): WorkerStatusesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
