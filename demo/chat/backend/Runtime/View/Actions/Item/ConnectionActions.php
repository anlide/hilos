<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;

/**
 * Write operations for a single connection (RtItem), mirroring DB item actions pattern.
 *
 * Closing a socket and re-pointing its user are the framework's own writes; what
 * is left here is chat's own moderation state for this socket. Its uploads are the
 * framework uploads agent's, which also removes them when the socket goes (HIL-144).
 *
 * @extends HilosConnectionActions<RuntimeConnection>
 * @property-read StateConnection $state
 */
final class ConnectionActions extends HilosConnectionActions
{
    /**
     * Start a connection-local outbound moderation state for a message and the files it carries.
     *
     * @param string $message Submitted message text
     * @param list<string> $attachmentIds Client ids of the complete uploads the message carries, in attach order
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function startOutboundModeration(string $message, array $attachmentIds): void
    {
        $this->ensureCanWrite();

        $this->state->outboundModerationPhase = ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING;
        $this->state->outboundModerationMessage = $message;
        $this->state->outboundModerationAttachments = $attachmentIds;
        $this->state->outboundModerationPicture = null;
        $this->state->outboundModerationReason = null;
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * Mark the current connection-local moderation as failed for user-visible retry.
     *
     * @param string $phase Failure phase: rejected or unavailable
     * @param string $reason User-visible reason
     * @param ?string $picture Client id of the picture the refusal is about, or null for a text refusal
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function failOutboundModeration(string $phase, string $reason, ?string $picture = null): void
    {
        $this->ensureCanWrite();

        $this->state->outboundModerationPhase = $phase;
        $this->state->outboundModerationReason = $reason;
        $this->state->outboundModerationPicture = $picture;
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * Clear current connection-local moderation state after approval.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clearOutboundModeration(): void
    {
        $this->ensureCanWrite();

        $this->state->outboundModerationPhase = ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE;
        $this->state->outboundModerationMessage = null;
        $this->state->outboundModerationAttachments = [];
        $this->state->outboundModerationPicture = null;
        $this->state->outboundModerationReason = null;
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();
    }
}
