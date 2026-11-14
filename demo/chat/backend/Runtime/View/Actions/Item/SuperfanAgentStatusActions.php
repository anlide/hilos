<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Runtime\State\Item\SuperfanAgentStatus as StateSuperfanAgentStatus;
use Demo\Chat\Runtime\View\Item\SuperfanAgentStatus as ViewSuperfanAgentStatus;
use Hilos\Core\Agent\Hilos\SuperfanRunStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;
use Hilos\HilosException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;

/**
 * Write operations for one superfan run status.
 *
 * @extends RtActions<ViewSuperfanAgentStatus, StateSuperfanAgentStatus>
 * @property-read StateSuperfanAgentStatus $state
 */
final class SuperfanAgentStatusActions extends RtActions
{
    /**
     * Set this superfan run status.
     *
     * @param SuperfanRunStatus $status Superfan run status
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function setStatus(SuperfanRunStatus $status): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateSuperfanAgentStatus::status => $status->value,
            StateSuperfanAgentStatus::updatedAt => time(),
        ]);
    }
}
