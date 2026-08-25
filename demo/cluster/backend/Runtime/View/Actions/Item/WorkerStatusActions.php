<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Actions\Item;

use Demo\Cluster\Runtime\State\Item\WorkerStatus as StateWorkerStatus;
use Demo\Cluster\Runtime\View\Item\WorkerStatus as ViewWorkerStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

/**
 * Write operations for one fleet worker status row.
 *
 * @extends RtActions<ViewWorkerStatus, StateWorkerStatus>
 * @property-read StateWorkerStatus $state
 */
final class WorkerStatusActions extends RtActions
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
