<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Actions\Collection;

use Demo\Cluster\Runtime\State\Collection\WorkerStatuses as StateWorkerStatuses;
use Demo\Cluster\Runtime\State\Item\WorkerStatus as StateWorkerStatus;
use Demo\Cluster\Runtime\View\Collection\WorkerStatuses;
use Demo\Cluster\Runtime\View\Item\WorkerStatus as ViewWorkerStatus;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Collection\RtActions;

/**
 * Write API for the fleet worker statuses.
 *
 * @extends RtActions<ViewWorkerStatus, WorkerStatuses, StateWorkerStatuses>
 * @property-read StateWorkerStatuses $stateCollection
 */
final class WorkerStatusesActions extends RtActions
{
    /**
     * Records what one fleet member has done and how much of the fleet it can see.
     *
     * Create and update in one call, because a fleet member reports the same way whether or not
     * its row already exists — it is the only writer of that row, on any node, and there is
     * nothing for it to find out first.
     *
     * @param string $workerIndex Fleet member index, and the row id
     * @param int $jobsDone Synthetic jobs the member has finished
     * @param int $rowsSeen Rows of this collection the member itself can see
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtActionsItemClassException When the runtime item class is missing or invalid
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source of this row
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
     */
    public function report(string $workerIndex, int $jobsDone, int $rowsSeen): void
    {
        $existing = $this->collection[$workerIndex] ?? null;
        if ($existing instanceof ViewWorkerStatus) {
            $existing->actions->report($jobsDone, $rowsSeen);

            return;
        }

        $this->ensureCanWriteState($workerIndex, TruthSourceOperation::Add);

        $state = StateWorkerStatus::create($workerIndex);
        $state->jobsDone = $jobsDone;
        $state->rowsSeen = $rowsSeen;
        $this->addStateToCollection($state);
    }
}
