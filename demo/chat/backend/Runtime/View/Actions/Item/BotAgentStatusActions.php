<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Item\BotAgentStatus as ViewBotAgentStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

/**
 * Write operations for one bot agent lifecycle status.
 *
 * @extends RtActions<ViewBotAgentStatus, StateBotAgentStatus>
 * @property-read StateBotAgentStatus $state
 */
final class BotAgentStatusActions extends RtActions
{
    /**
     * Mark this bot agent as joined.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function markJoined(): void
    {
        $this->setStatus(StateBotAgentStatus::STATUS_JOINED);
    }

    /**
     * Mark this bot agent as left.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function markLeft(): void
    {
        $this->setStatus(StateBotAgentStatus::STATUS_LEFT);
    }

    /**
     * @param string $status Lifecycle marker
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    private function setStatus(string $status): void
    {
        $this->ensureCanWrite();

        $this->state->status = $status;
        $this->state->updatedAt = time();

        $this->sync();
    }
}
