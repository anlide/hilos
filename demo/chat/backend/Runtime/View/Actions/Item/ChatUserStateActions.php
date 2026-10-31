<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\View\Item\ChatUserState as ViewChatUserState;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

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
     */
    public function recordOutboundSubmission(): void
    {
        $this->ensureCanWrite();

        $this->state->lastOutboundSubmittedAt = microtime(true);

        $this->sync();
    }

    /**
     * Age the moment of the last accepted outbound submit by the whole rate-limit period.
     *
     * The moment stays a moment of sending, only its time moves, so the ordinary rate-limit rule accepts the
     * next submit at once. The period and its tolerance are the product's own and do not change here.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function endSendPause(): void
    {
        $this->ensureCanWrite();

        $this->state->lastOutboundSubmittedAt = microtime(true) - ViewChatUserState::MESSAGE_RATE_LIMIT_SECONDS;

        $this->sync();
    }
}
