<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Runtime\State\Item\GuardianAgentStatus as StateGuardianAgentStatus;
use Demo\Chat\Runtime\View\Item\GuardianAgentStatus as ViewGuardianAgentStatus;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

/**
 * Write operations for one guardian run status.
 *
 * @extends RtActions<ViewGuardianAgentStatus, StateGuardianAgentStatus>
 * @property-read StateGuardianAgentStatus $state
 */
final class GuardianAgentStatusActions extends RtActions
{
    /**
     * Set this guardian run status.
     *
     * @param GuardianRunStatus $status Guardian run status
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function setStatus(GuardianRunStatus $status): void
    {
        $this->ensureCanWrite();

        $this->state->status = $status->value;
        $this->state->updatedAt = time();

        $this->sync();
    }
}
