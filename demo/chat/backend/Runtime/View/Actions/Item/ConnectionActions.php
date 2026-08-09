<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
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
     * @throws FileDeleteException When an active quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When this connection is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function unregister(): void
    {
        $quarantineBasename = $this->state->fileSessionQuarantineBasename;
        if ($quarantineBasename !== null) {
            Hilos::$fs->tmp[$quarantineBasename]->unlink();
        }

        $this->remove();
    }

    /**
     * Re-points this connection to an authenticated user, or back to anonymous.
     *
     * The RT side of the session upgrade/downgrade seam: authenticateSession binds
     * the live connection to the logged-in user id, logout reverts it to null.
     *
     * @param ?int $userId Authenticated user id, or null for anonymous
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function bindUser(?int $userId): void
    {
        $this->ensureCanWrite();

        $this->state->userId = $userId;

        $this->sync();
    }

    /**
     * Start a connection-local outbound moderation state.
     *
     * @param string $message Submitted message text
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function startOutboundModeration(string $message): void
    {
        $this->ensureCanWrite();

        $this->state->outboundModerationPhase = ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING;
        $this->state->outboundModerationMessage = $message;
        $this->state->outboundModerationReason = null;
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * Mark the current connection-local moderation as failed for user-visible retry.
     *
     * @param string $phase Failure phase: rejected or unavailable
     * @param string $reason User-visible reason
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
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
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clearOutboundModeration(): void
    {
        $this->ensureCanWrite();

        $this->state->outboundModerationPhase = ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE;
        $this->state->outboundModerationMessage = null;
        $this->state->outboundModerationReason = null;
        $this->state->outboundModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * Start connection-local moderation for a requested display name.
     *
     * @param string $newName Requested display name
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function startRenameModeration(string $newName): void
    {
        $this->ensureCanWrite();

        $this->state->renameModerationPhase = ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING;
        $this->state->renameModerationName = $newName;
        $this->state->renameModerationReason = null;
        $this->state->renameModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * Mark the current rename moderation as failed for user-visible retry.
     *
     * @param string $phase Failure phase: rejected or unavailable
     * @param string $reason User-visible reason
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function failRenameModeration(string $phase, string $reason): void
    {
        $this->ensureCanWrite();

        $this->state->renameModerationPhase = $phase;
        $this->state->renameModerationReason = $reason;
        $this->state->renameModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * Clear current connection-local rename moderation state after approval.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clearRenameModeration(): void
    {
        $this->ensureCanWrite();

        $this->state->renameModerationPhase = ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_NONE;
        $this->state->renameModerationName = null;
        $this->state->renameModerationReason = null;
        $this->state->renameModerationUpdatedAt = time();

        $this->sync();
    }

    /**
     * After successful FILE_UPLOAD_INIT: open session row, progress UI, and ready state on this socket.
     *
     * Browser selfConnection rows send the ready state and 0 / total baseline.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
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
        $this->state->fileUploadPhase = ConnectionRuntimeConstants::FILE_UPLOAD_PHASE_READY;
        $this->state->fileUploadClientUploadId = $clientUploadId;
        $this->state->fileUploadErrorCode = null;
        $this->state->fileUploadErrorMessage = null;
        $this->state->fileProgressFilename = $progressFilename;
        $this->state->fileProgressUploadedBytes = 0;
        $this->state->fileProgressTotalBytes = $progressTotalBytes;

        $this->sync();
    }

    /**
     * Delete any active upload tmp file and clear upload session/progress state.
     *
     * @throws FileDeleteException When the active tmp file cannot be deleted
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function discardActiveBinaryUploadSessionAndProgressUi(): void
    {
        $quarantineBasename = $this->state->fileSessionQuarantineBasename;
        if ($quarantineBasename !== null) {
            Hilos::$fs->tmp[$quarantineBasename]->unlink();
        }

        $this->clearBinaryUploadSessionAndProgressUi();
    }

    /**
     * Clear binary upload session and upload-progress UI (e.g. after receive complete or abort).
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clearBinaryUploadSessionAndProgressUi(): void
    {
        $this->ensureCanWrite();

        $this->resetBinaryUploadSessionFields();
        $this->resetUploadProgressUiFields();
        $this->resetUploadStateFields();

        $this->sync();
    }

    /**
     * Clear all file-runtime fields on this socket (session and progress UI).
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clearAllFileRuntimeOnSocket(): void
    {
        $this->ensureCanWrite();

        $this->resetBinaryUploadSessionFields();
        $this->resetUploadProgressUiFields();
        $this->resetUploadStateFields();

        $this->sync();
    }

    /**
     * Clear active upload runtime fields and expose a retryable upload failure.
     *
     * @param ?string $clientUploadId Client-side upload correlation id, if known
     * @param string $code Short failure code for frontend behavior
     * @param string $message User-facing failure message
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function failBinaryFileUpload(?string $clientUploadId, string $code, string $message): void
    {
        $this->ensureCanWrite();

        $this->resetBinaryUploadSessionFields();
        $this->resetUploadProgressUiFields();
        $this->state->fileUploadPhase = ConnectionRuntimeConstants::FILE_UPLOAD_PHASE_FAILED;
        $this->state->fileUploadClientUploadId = $clientUploadId;
        $this->state->fileUploadErrorCode = $code;
        $this->state->fileUploadErrorMessage = $message;

        $this->sync();
    }

    /**
     * Delete the active tmp file, clear session fields, and expose a retryable upload failure.
     *
     * @param ?string $fallbackClientUploadId Client correlation id when the session no longer carries one
     * @param string $code Short failure code for frontend behavior
     * @param string $message User-facing failure message
     * @throws FileDeleteException When the active tmp file cannot be deleted
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function failActiveBinaryFileUpload(?string $fallbackClientUploadId, string $code, string $message): void
    {
        $clientUploadId = $this->state->fileSessionClientUploadId ?? $fallbackClientUploadId;

        $quarantineBasename = $this->state->fileSessionQuarantineBasename;
        if ($quarantineBasename !== null) {
            Hilos::$fs->tmp[$quarantineBasename]->unlink();
        }

        $this->failBinaryFileUpload($clientUploadId, $code, $message);
    }

    /**
     * Update stored received bytes and progress-bar uploaded bytes after a binary chunk.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function applyStoredBinaryChunkProgress(int $newReceivedBytes): void
    {
        $this->ensureCanWrite();

        $this->state->fileSessionReceivedBytes = $newReceivedBytes;
        $this->state->fileProgressUploadedBytes = $newReceivedBytes;

        $this->sync();
    }

    /**
     * Store one binary upload payload, update progress, and report whether the upload completed.
     *
     * @param string $payload Raw websocket binary frame payload
     * @param float $progressMinIntervalSec Minimum progress notification interval in seconds
     * @return bool True when received bytes reached the declared upload size
     * @throws FileNotFoundException When no upload session is open, so no tmp file is named
     * @throws FsException When the tmp file cannot be appended
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function storeBinaryFileUploadChunk(string $payload, float $progressMinIntervalSec): bool
    {
        $this->ensureCanWrite();

        $quarantineBasename = $this->state->fileSessionQuarantineBasename
            ?? throw new FileNotFoundException('No upload session is open on this connection');

        Hilos::$fs->tmp[$quarantineBasename]->append($payload);

        $this->state->fileSessionReceivedBytes += strlen($payload);
        $this->state->fileProgressUploadedBytes = $this->state->fileSessionReceivedBytes;

        if (
            $this->state->fileProgressFilename !== null
            && (
                $this->state->fileSessionReceivedBytes === $this->state->fileSessionDeclaredSize
                || (
                    $this->state->fileProgressTotalBytes > 0
                    && $this->state->fileProgressUploadedBytes >= $this->state->fileProgressTotalBytes
                )
                || $this->state->uploadProgressLastSentAt <= 0.0
                || microtime(true) - $this->state->uploadProgressLastSentAt >= $progressMinIntervalSec
            )
        ) {
            if ($this->state->fileSessionUploadId !== null && $this->state->fileSessionReceivedBytes > 0) {
                $this->state->fileUploadPhase = ConnectionRuntimeConstants::FILE_UPLOAD_PHASE_UPLOADING;
            }
            $this->state->uploadProgressLastSentAt = microtime(true);
        }

        $this->sync();

        return $this->state->fileSessionReceivedBytes === $this->state->fileSessionDeclaredSize;
    }

    /**
     * Move the completed tmp upload to quarantine, create a draft row, and clear upload UI state.
     *
     * @throws FileNotFoundException When no upload session is open, so there is nothing to complete
     * @throws FsException When the completed tmp file cannot be moved to quarantine
     * @throws RtActionsCallbackNotSetException When attachment draft creation is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When attachment draft runtime state is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function completeBinaryFileUpload(): void
    {
        $this->ensureCanWrite();

        $uploadId = $this->state->fileSessionUploadId;
        $tmpBasename = $this->state->fileSessionQuarantineBasename;
        $mimeType = $this->state->fileSessionMimeType;
        $originalFilename = $this->state->fileSessionOriginalFilename;
        $normalizedFilename = $this->state->fileSessionNormalizedFilename;
        if (
            $uploadId === null
            || $tmpBasename === null
            || $mimeType === null
            || $originalFilename === null
            || $normalizedFilename === null
        ) {
            throw new FileNotFoundException('No upload session is open on this connection');
        }

        $quarantineBasename = $uploadId . FsFile::extensionForMime($mimeType);
        Hilos::$fs->quarantine->createFromTmp($quarantineBasename, $tmpBasename);

        Hilos::$rt->attachmentDrafts->actions->create(
            draftId: $uploadId,
            acceptKey: $this->state->acceptKey,
            userId: (int)$this->state->userId,
            quarantineBasename: $quarantineBasename,
            originalFilename: $originalFilename,
            mimeType: $mimeType,
            size: $this->state->fileSessionDeclaredSize,
            normalizedFilename: $normalizedFilename,
            uploadedAt: time(),
        );

        $this->resetBinaryUploadSessionFields();
        $this->resetUploadProgressUiFields();
        $this->resetUploadStateFields();

        $this->sync();
    }

    /**
     * Record last upload-progress browser notify time.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function noteUploadProgressSentAt(): void
    {
        $this->ensureCanWrite();

        if ($this->state->fileSessionUploadId !== null && $this->state->fileSessionReceivedBytes > 0) {
            $this->state->fileUploadPhase = ConnectionRuntimeConstants::FILE_UPLOAD_PHASE_UPLOADING;
        }
        $this->state->uploadProgressLastSentAt = microtime(true);

        $this->sync();
    }

    private function resetBinaryUploadSessionFields(): void
    {
        $this->state->fileSessionUploadId = null;
        $this->state->fileSessionDeclaredSize = 0;
        $this->state->fileSessionReceivedBytes = 0;
        $this->state->fileSessionQuarantineBasename = null;
        $this->state->fileSessionOriginalFilename = null;
        $this->state->fileSessionMimeType = null;
        $this->state->fileSessionClientUploadId = null;
        $this->state->fileSessionNormalizedFilename = null;
    }

    private function resetUploadStateFields(): void
    {
        $this->state->fileUploadPhase = ConnectionRuntimeConstants::FILE_UPLOAD_PHASE_IDLE;
        $this->state->fileUploadClientUploadId = null;
        $this->state->fileUploadErrorCode = null;
        $this->state->fileUploadErrorMessage = null;
    }

    private function resetUploadProgressUiFields(): void
    {
        $this->state->fileProgressFilename = null;
        $this->state->fileProgressUploadedBytes = 0;
        $this->state->fileProgressTotalBytes = 0;
        $this->state->uploadProgressLastSentAt = 0.0;
    }
}
