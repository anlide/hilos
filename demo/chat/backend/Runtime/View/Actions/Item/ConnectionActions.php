<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

/**
 * Write operations for a single connection (RtItem), mirroring DB item actions pattern.
 *
 * @extends RtActions<RuntimeConnection, StateConnection>
 * @property-read StateConnection $state
 */
final class ConnectionActions extends RtActions
{
    /**
     * Remove this connection and its active quarantine `.part` file when present.
     *
     * @throws FileDeleteException When an active quarantine file cannot be deleted.
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function unregister(): void
    {
        if ($this->state->fileSessionUploadId !== null) {
            Hilos::$fs->tmp[$this->state->fileSessionQuarantineBasename]->unlink();
        }

        $this->remove();
    }

    /**
     * Start a connection-local outbound moderation state.
     *
     * @param string $message Submitted message text
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function startOutboundModeration(string $message): void
    {
        $this->ensureCanWrite();

        $this->state->outboundModerationPhase = RuntimeConnection::OUTBOUND_MODERATION_PHASE_CHECKING;
        $this->state->outboundModerationMessage = $message;
        $this->state->outboundModerationReason = '';
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * Mark the current connection-local moderation as failed for user-visible retry.
     *
     * @param string $phase Failure phase: rejected or unavailable
     * @param string $reason User-visible reason
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function failOutboundModeration(string $phase, string $reason): void
    {
        $this->ensureCanWrite();

        $this->state->outboundModerationPhase = $phase;
        $this->state->outboundModerationReason = $reason;
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * Clear current connection-local moderation state after approval.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function clearOutboundModeration(): void
    {
        $this->ensureCanWrite();

        $this->state->outboundModerationPhase = RuntimeConnection::OUTBOUND_MODERATION_PHASE_NONE;
        $this->state->outboundModerationMessage = '';
        $this->state->outboundModerationReason = '';
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * After successful FILE_UPLOAD_INIT: open session row + progress bar fields on this socket.
     *
     * Caller should send {@see ChatSignalConstants::FILE_UPLOAD_READY} and then record the
     * upload-progress throttle time ({@see self::noteUploadProgressSentAt()}) so the first binary chunk does not
     * duplicate that baseline.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function beginBinaryFileUpload(
        string $uploadId,
        int $declaredSize,
        string $quarantineBasename,
        string $originalFilename,
        string $mimeType,
        string $clientUploadId,
        string $normalizedFilename,
        string $progressFilename,
        int $progressTotalBytes,
    ): void {
        $this->ensureCanWrite();

        $this->state->fileSessionUploadId = $uploadId;
        $this->state->fileSessionDeclaredSize = $declaredSize;
        $this->state->fileSessionReceivedBytes = 0;
        $this->state->fileSessionQuarantineBasename = $quarantineBasename;
        $this->state->fileSessionOriginalFilename = $originalFilename;
        $this->state->fileSessionMimeType = $mimeType;
        $this->state->fileSessionClientUploadId = $clientUploadId;
        $this->state->fileSessionNormalizedFilename = $normalizedFilename;
        $this->state->fileProgressFilename = $progressFilename;
        $this->state->fileProgressUploadedBytes = 0;
        $this->state->fileProgressTotalBytes = $progressTotalBytes;

        $this->sync();
    }

    /**
     * Clear binary upload session and upload-progress UI (e.g. after receive complete or abort).
     * @throws RtActionsCollectionNameNullException
     */
    public function clearBinaryUploadSessionAndProgressUi(): void
    {
        $this->ensureCanWrite();

        $this->resetBinaryUploadSessionFields();
        $this->resetUploadProgressUiFields();

        $this->sync();
    }

    /**
     * Clear all file-runtime fields on this socket (session and progress UI).
     * @throws RtActionsCollectionNameNullException
     */
    public function clearAllFileRuntimeOnSocket(): void
    {
        $this->ensureCanWrite();

        $this->resetBinaryUploadSessionFields();
        $this->resetUploadProgressUiFields();

        $this->sync();
    }

    /**
     * Update stored received bytes and progress-bar uploaded bytes after a binary chunk.
     * @throws RtActionsCollectionNameNullException
     */
    public function applyStoredBinaryChunkProgress(int $newReceivedBytes): void
    {
        $this->ensureCanWrite();

        $this->state->fileSessionReceivedBytes = $newReceivedBytes;
        $this->state->fileProgressUploadedBytes = $newReceivedBytes;

        $this->sync();
    }

    /**
     * Record last upload-progress notify time (throttle): {@see ChatSignalConstants::FILE_UPLOAD_READY}
     * or {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE}.
     *
     * @throws RtActionsCollectionNameNullException
     */
    public function noteUploadProgressSentAt(float $sentAtMicrotime): void
    {
        $this->ensureCanWrite();

        $this->state->uploadProgressLastSentAt = $sentAtMicrotime;

        $this->sync();
    }

    private function resetBinaryUploadSessionFields(): void
    {
        $this->state->fileSessionUploadId = null;
        $this->state->fileSessionDeclaredSize = 0;
        $this->state->fileSessionReceivedBytes = 0;
        $this->state->fileSessionQuarantineBasename = '';
        $this->state->fileSessionOriginalFilename = '';
        $this->state->fileSessionMimeType = '';
        $this->state->fileSessionClientUploadId = '';
        $this->state->fileSessionNormalizedFilename = '';
    }

    private function resetUploadProgressUiFields(): void
    {
        $this->state->fileProgressFilename = null;
        $this->state->fileProgressUploadedBytes = 0;
        $this->state->fileProgressTotalBytes = 0;
        $this->state->uploadProgressLastSentAt = 0.0;
    }
}
