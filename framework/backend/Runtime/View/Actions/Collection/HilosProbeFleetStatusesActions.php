<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Runtime\State\Collection\HilosProbeFleetStatuses as StateHilosProbeFleetStatuses;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus as StateHilosProbeFleetStatus;
use Hilos\Runtime\View\Collection\HilosProbeFleetStatuses;
use Hilos\Runtime\View\Item\HilosProbeFleetStatus as ViewHilosProbeFleetStatus;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;

/**
 * Write API for the probe fleet statuses.
 *
 * @extends RtActions<ViewHilosProbeFleetStatus, HilosProbeFleetStatuses, StateHilosProbeFleetStatuses>
 * @property-read StateHilosProbeFleetStatuses $stateCollection
 */
final class HilosProbeFleetStatusesActions extends RtActions
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
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function report(string $workerIndex, int $jobsDone, int $rowsSeen): void
    {
        $existing = $this->collection[$workerIndex] ?? null;
        if ($existing instanceof ViewHilosProbeFleetStatus) {
            $existing->actions->report($jobsDone, $rowsSeen);

            return;
        }

        // The row class names no SET_VIA field, so a worker status row is in no set and has no set key.
        $this->ensureCanWriteState($workerIndex, [], TruthSourceOperation::Add);

        $state = StateHilosProbeFleetStatus::create($workerIndex);
        $state->jobsDone = $jobsDone;
        $state->rowsSeen = $rowsSeen;
        $this->addStateToCollection($state);
    }
}
