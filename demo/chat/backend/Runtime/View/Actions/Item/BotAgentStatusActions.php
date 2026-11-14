<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Item\BotAgentStatus as ViewBotAgentStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;
use Hilos\HilosException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;

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
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markJoined(): void
    {
        $this->setStatus(StateBotAgentStatus::STATUS_JOINED);
    }

    /**
     * Mark this bot agent as left.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markLeft(): void
    {
        $this->setStatus(StateBotAgentStatus::STATUS_LEFT);
    }

    /**
     * @param string $status Lifecycle marker
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    private function setStatus(string $status): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateBotAgentStatus::status => $status,
            StateBotAgentStatus::updatedAt => time(),
        ]);
    }
}
