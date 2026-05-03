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
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function recordOutboundSubmission(): void
    {
        $this->ensureCanWrite();

        $this->state->lastOutboundSubmittedAt = microtime(true);

        $this->sync();
    }
}
