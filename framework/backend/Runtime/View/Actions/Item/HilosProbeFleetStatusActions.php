<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Runtime\State\Item\HilosProbeFleetStatus as StateHilosProbeFleetStatus;
use Hilos\Runtime\View\Item\HilosProbeFleetStatus as ViewHilosProbeFleetStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\HilosException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;

/**
 * Write operations for one probe fleet status row.
 *
 * @extends RtActions<ViewHilosProbeFleetStatus, StateHilosProbeFleetStatus>
 * @property-read StateHilosProbeFleetStatus $state
 */
final class HilosProbeFleetStatusActions extends RtActions
{
    /**
     * Records this member's latest report.
     *
     * @param int $jobsDone Synthetic jobs the member has finished
     * @param int $rowsSeen Rows of this collection the member itself can see
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source of this row
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function report(int $jobsDone, int $rowsSeen): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateHilosProbeFleetStatus::jobsDone => $jobsDone,
            StateHilosProbeFleetStatus::rowsSeen => $rowsSeen,
            StateHilosProbeFleetStatus::updatedAt => time(),
        ]);
    }
}
