<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
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
     * After successful FILE_UPLOAD_INIT: open session row + progress bar fields on this socket.
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
        $this->state->uploadProgressLastSentAt = 0.0;

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
     * Clear file-moderation banner state on this socket.
     * @throws RtActionsCollectionNameNullException
     */
    public function clearFileModerationBanner(): void
    {
        $this->ensureCanWrite();

        $this->resetFileModerationUiFields();

        $this->sync();
    }

    /**
     * Clear all file-runtime fields on this socket (session, progress UI, moderation banner).
     * @throws RtActionsCollectionNameNullException
     */
    public function clearAllFileRuntimeOnSocket(): void
    {
        $this->ensureCanWrite();

        $this->resetBinaryUploadSessionFields();
        $this->resetUploadProgressUiFields();
        $this->resetFileModerationUiFields();

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
     * Enter "moderating" file banner while ModeratorAgent runs.
     * @throws RtActionsCollectionNameNullException
     */
    public function enterFileModerationPending(string $originalFilename, int $sizeBytes): void
    {
        $this->ensureCanWrite();

        $this->state->fileModPhase = 'moderating';
        $this->state->fileModFilename = $originalFilename;
        $this->state->fileModUploadedBytes = $sizeBytes;
        $this->state->fileModTotalBytes = $sizeBytes;
        $this->state->fileModReason = '';
        $this->state->fileModUpdatedAt = time();

        $this->sync();
    }

    /**
     * Show rejected-file banner after moderator denial.
     * @throws RtActionsCollectionNameNullException
     */
    public function markFileModerationRejected(
        string $originalFilename,
        int $sizeBytes,
        string $reason,
    ): void {
        $this->ensureCanWrite();

        $this->state->fileModPhase = 'rejected';
        $this->state->fileModFilename = $originalFilename;
        $this->state->fileModUploadedBytes = $sizeBytes;
        $this->state->fileModTotalBytes = $sizeBytes;
        $this->state->fileModReason = $reason;
        $this->state->fileModUpdatedAt = time();

        $this->sync();
    }

    /**
     * Record last FILE_UPLOAD_PROGRESS_UPDATE send time (throttle).
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

    private function resetFileModerationUiFields(): void
    {
        $this->state->fileModPhase = null;
        $this->state->fileModFilename = '';
        $this->state->fileModUploadedBytes = 0;
        $this->state->fileModTotalBytes = 0;
        $this->state->fileModReason = '';
        $this->state->fileModUpdatedAt = 0;
    }
}
