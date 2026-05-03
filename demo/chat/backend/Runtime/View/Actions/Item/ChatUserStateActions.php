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
     * Record an accepted outbound submit for rate limiting.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function recordOutboundSubmitted(): void
    {
        $this->ensureCanWrite();

        $this->state->lastOutboundSubmittedAt = microtime(true);

        $this->sync();
    }

    /**
     * Start a user-visible outbound moderation state.
     *
     * @param string $requestId Moderation request id
     * @param string $message Submitted message text
     * @param list<string> $attachmentDraftIds Submitted attachment draft ids
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function startOutboundModeration(string $requestId, string $message, array $attachmentDraftIds): void
    {
        $this->ensureCanWrite();

        $this->state->outboundModerationRequestId = $requestId;
        $this->state->outboundModerationPhase = ViewChatUserState::OUTBOUND_MODERATION_PHASE_CHECKING;
        $this->state->outboundModerationMessage = $message;
        $this->state->outboundModerationAttachmentDraftIdsJson = json_encode(array_values($attachmentDraftIds)) ?: '[]';
        $this->state->outboundModerationReason = '';
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * Mark the current outbound moderation as failed for user-visible retry.
     *
     * @param string $requestId Moderation request id to match
     * @param string $phase Failure phase: rejected or unavailable
     * @param string $reason User-visible reason
     * @return bool True when the state matched and was updated
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function failOutboundModeration(string $requestId, string $phase, string $reason): bool
    {
        $this->ensureCanWrite();

        if ($this->state->outboundModerationRequestId !== $requestId) {
            return false;
        }

        $this->state->outboundModerationPhase = $phase;
        $this->state->outboundModerationReason = $reason;
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();

        return true;
    }

    /**
     * Clear current outbound moderation state after approval.
     *
     * @param string $requestId Moderation request id to match
     * @return bool True when the state matched and was cleared
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function clearOutboundModeration(string $requestId): bool
    {
        $this->ensureCanWrite();

        if ($this->state->outboundModerationRequestId !== $requestId) {
            return false;
        }

        $this->state->outboundModerationRequestId = '';
        $this->state->outboundModerationPhase = ViewChatUserState::OUTBOUND_MODERATION_PHASE_NONE;
        $this->state->outboundModerationMessage = '';
        $this->state->outboundModerationAttachmentDraftIdsJson = '[]';
        $this->state->outboundModerationReason = '';
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();

        return true;
    }
}
