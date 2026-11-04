<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Runtime\State\Item\SuperfanAgentStatus as StateSuperfanAgentStatus;
use Demo\Chat\Runtime\View\Item\SuperfanAgentStatus as ViewSuperfanAgentStatus;
use Hilos\Core\Agent\Hilos\SuperfanRunStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

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
     */
    public function setStatus(SuperfanRunStatus $status): void
    {
        $this->ensureCanWrite();

        $this->state->status = $status->value;
        $this->state->updatedAt = time();

        $this->sync();
    }
}
