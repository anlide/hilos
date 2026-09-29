<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Runtime\State\Item\HilosProbeFleetStatus as StateHilosProbeFleetStatus;
use Hilos\Runtime\View\Item\HilosProbeFleetStatus as ViewHilosProbeFleetStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;

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
     */
    public function report(int $jobsDone, int $rowsSeen): void
    {
        $this->ensureCanWrite();

        $this->state->jobsDone = $jobsDone;
        $this->state->rowsSeen = $rowsSeen;
        $this->state->updatedAt = time();

        $this->sync();
    }
}
