<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\View\Item\ChatUserState as ViewChatUserState;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;
use Hilos\HilosException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;

/**
 * Write operations for one user's chat runtime state.
 *
 * @extends RtActions<ViewChatUserState, StateChatUserState>
 * @property-read StateChatUserState $state
 */
final class ChatUserStateActions extends RtActions
{
    /**
     * Record the shared per-user outbound submit timer.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function recordOutboundSubmission(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateChatUserState::lastOutboundSubmittedAt => microtime(true),
        ]);
    }

    /**
     * Age the moment of the last accepted outbound submit by the whole rate-limit period.
     *
     * The moment stays a moment of sending, only its time moves, so the ordinary rate-limit rule accepts the
     * next submit at once. The period and its tolerance are the product's own and do not change here.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function endSendPause(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateChatUserState::lastOutboundSubmittedAt => microtime(true) - ViewChatUserState::MESSAGE_RATE_LIMIT_SECONDS,
        ]);
    }
}
