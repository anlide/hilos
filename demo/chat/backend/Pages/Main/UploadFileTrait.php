<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Router\DTO\FileUploadAbortedSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadCompleteSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadInvalidSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadReadySignalData;
use Demo\Chat\Core\Router\DTO\FileUploadRejectedSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\Connection;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Helpers\FileSystemHelper;

/**
 * Main-page binary upload flow; completed uploads become attachment drafts.
 */
trait UploadFileTrait
{
    /**
     * Minimum wall-clock interval between projected upload-progress notifications when not forced.
     */
    private const float FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC = 0.3;

    /**
     * Handle {@see ChatSignalConstants::FILE_UPLOAD_INIT}: validate limits and filename, create tmp file,
     * start session, send {@see ChatSignalConstants::FILE_UPLOAD_READY}. Replaces an in-flight upload on the same socket.
     *
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the current outbound submit is being moderated
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws RtTruthSourceWriteNotAllowedException When the truth source rejects a runtime write
     * @throws FileDeleteException When upload cleanup cannot delete tmp or quarantine files
     */
    protected function handleFileUploadInit(FileUploadInitActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        if (Hilos::$rt->selfConnection->outboundModerationPhase === Connection::OUTBOUND_MODERATION_PHASE_CHECKING) {
            throw new ValidationException('Cannot upload attachments while message is being moderated');
        }

        if (Hilos::$rt->selfConnection->fileSessionUploadId !== null) {
            Hilos::$fs->tmp[Hilos::$rt->selfConnection->fileSessionQuarantineBasename]->unlink();
            Hilos::$rt->selfConnection->actions->clearBinaryUploadSessionAndProgressUi();
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_ABORTED,
                Hilos::$rt->selfConnection->acceptKey,
                new FileUploadAbortedSignalData('superseded_by_new_init'),
            );
        }

        if (!$dto->isValid()) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                Hilos::$rt->selfConnection->acceptKey,
                new FileUploadRejectedSignalData('invalid_payload', 'Invalid file metadata'),
            );

            return;
        }

        Hilos::$rt->attachmentDrafts->actions->deleteExpired();

        $maxFile = ChatSettingsHelper::getAttachmentMaxFileBytes();
        $maxTotal = ChatSettingsHelper::getAttachmentMaxTotalBytes();
        if ($dto->size > $maxFile) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                Hilos::$rt->selfConnection->acceptKey,
                new FileUploadRejectedSignalData('size_limit', 'File exceeds maximum allowed size'),
            );

            return;
        }

        $publishedTotal = Hilos::$db->eventAttachments->sumPublishedAttachmentBytes();
        $reserved = Hilos::$rt->connections->sumActiveUploadReservedBytes();
        $draftTotal = Hilos::$rt->attachmentDrafts->sumDraftBytes();
        if ($publishedTotal + $reserved + $draftTotal + $dto->size > $maxTotal) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                Hilos::$rt->selfConnection->acceptKey,
                new FileUploadRejectedSignalData('total_limit', 'Total attachment storage limit would be exceeded'),
            );

            return;
        }

        $norm = FileSystemHelper::normalizeBasename($dto->filename);
        if ($this->isFilenameInUse($norm)) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                Hilos::$rt->selfConnection->acceptKey,
                new FileUploadRejectedSignalData('duplicate_filename', 'A file with this name already exists'),
            );

            return;
        }

        try {
            $tmpIndex = Hilos::$fs->tmp->create();
        } catch (FsException $e) {
            $this->logAgentError("Cannot create tmp file: {$e->getMessage()}");
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                Hilos::$rt->selfConnection->acceptKey,
                new FileUploadRejectedSignalData('storage_error', 'Cannot start upload'),
            );

            return;
        }

        Hilos::$rt->selfConnection->actions->beginBinaryFileUpload(
            $tmpIndex,
            $dto->size,
            $tmpIndex,
            $dto->filename,
            $dto->mimeType,
            $dto->clientUploadId,
            $norm,
            $dto->filename,
            $dto->size,
        );

        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_READY,
            Hilos::$rt->selfConnection->acceptKey,
            new FileUploadReadySignalData(
                uploadId: $tmpIndex,
                filename: $dto->filename,
                mimeType: $dto->mimeType,
                size: $dto->size,
                clientUploadId: $dto->clientUploadId,
            ),
        );
        // READY is protocol permission; this marker lets frontend projection publish the 0 / size progress baseline.
        Hilos::$rt->selfConnection->actions->noteUploadProgressSentAt(microtime(true));
    }

    /**
     * Handle a websocket binary frame for an active main-page upload session.
     *
     * Appends the chunk to tmp storage, updates runtime progress, records throttled projection markers,
     * and completes the upload when received bytes reach the declared size.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload and connection id
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws FileDeleteException When an invalid upload cannot delete its tmp file
     */
    protected function handleFileUploadBinaryFrame(WebSocketFrameBinarySignalDTO $data): void
    {
        if (Hilos::$rt->selfConnection === null) {
            return;
        }
        if (Hilos::$rt->selfConnection->fileSessionUploadId === null) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_INVALID,
                Hilos::$rt->selfConnection->acceptKey,
                new FileUploadInvalidSignalData('no_active_upload'),
            );

            return;
        }

        $len = strlen($data->payload);
        $declared = Hilos::$rt->selfConnection->fileSessionDeclaredSize;
        $received = Hilos::$rt->selfConnection->fileSessionReceivedBytes;
        if ($received + $len > $declared) {
            $this->logAgentError(
                'frame_binary: overflow acceptKey=' . Hilos::$rt->selfConnection->acceptKey
                . ' userId=' . Hilos::$rt->selfConnection->userId,
            );
            $this->failFileUploadSession('size_overflow');

            return;
        }

        $tmpIndex = Hilos::$rt->selfConnection->fileSessionQuarantineBasename;
        try {
            Hilos::$fs->tmp[$tmpIndex]->append($data->payload);
        } catch (FsException $e) {
            $this->logAgentError(
                'frame_binary: tmp append failed acceptKey=' . Hilos::$rt->selfConnection->acceptKey
                . ' userId=' . Hilos::$rt->selfConnection->userId
                . ' error=' . $e->getMessage(),
            );
            $this->failFileUploadSession('write_error');

            return;
        }

        $newReceived = $received + $len;
        Hilos::$rt->selfConnection->actions->applyStoredBinaryChunkProgress($newReceived);

        $this->noteFileUploadProgressProjectionThrottled($newReceived === $declared);

        if ($newReceived === $declared) {
            $this->completeFileUpload();
        }
    }

    /**
     * Delete all attachment files on disk and reset file-related runtime fields on every connection.
     *
     * Runtime sync projection updates subscribed tabs so they drop draft and progress UI.
     */
    protected function deleteAllAttachmentFilesFromDisk(): void
    {
        Hilos::$fs->published->deleteAll();
        Hilos::$fs->quarantine->deleteAll();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$rt->connections->actions->clearAllFileRuntimeOnAllConnections();
    }

    /**
     * After the last binary chunk: move tmp to quarantine, clear upload session and progress UI,
     * create an attachment draft, and send a payloadless {@see ChatSignalConstants::FILE_UPLOAD_COMPLETE} marker.
     *
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws FileDeleteException When failed upload cleanup cannot delete its tmp file
     */
    private function completeFileUpload(): void
    {
        if (Hilos::$rt->selfConnection === null) {
            return;
        }
        if (Hilos::$rt->selfConnection->fileSessionUploadId === null) {
            return;
        }
        $uploadId = Hilos::$rt->selfConnection->fileSessionUploadId;
        $tmpIndex = Hilos::$rt->selfConnection->fileSessionQuarantineBasename;
        $declaredSize = Hilos::$rt->selfConnection->fileSessionDeclaredSize;
        $originalFilename = Hilos::$rt->selfConnection->fileSessionOriginalFilename;
        $mimeType = Hilos::$rt->selfConnection->fileSessionMimeType;
        $normalizedFilename = Hilos::$rt->selfConnection->fileSessionNormalizedFilename;

        $storedName = $uploadId . FsFile::extensionForMime($mimeType);
        try {
            Hilos::$fs->quarantine->createFromTmp($storedName, $tmpIndex);
        } catch (FsException $e) {
            $this->logAgentError("Cannot move tmp to quarantine: {$e->getMessage()}");
            $this->failFileUploadSession('storage_error');

            return;
        }

        Hilos::$rt->selfConnection->actions->clearBinaryUploadSessionAndProgressUi();
        Hilos::$rt->attachmentDrafts->actions->create(
            draftId: $uploadId,
            acceptKey: Hilos::$rt->selfConnection->acceptKey,
            userId: Hilos::$rt->selfConnection->userId,
            quarantineBasename: $storedName,
            originalFilename: $originalFilename,
            mimeType: $mimeType,
            size: $declaredSize,
            normalizedFilename: $normalizedFilename,
            uploadedAt: time(),
        );
        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_COMPLETE,
            Hilos::$rt->selfConnection->acceptKey,
            new FileUploadCompleteSignalData(),
        );
    }

    /**
     * Abort the active upload: delete tmp file, clear session/progress runtime, notify the client.
     *
     * @param string $reason Short code forwarded in {@see FileUploadInvalidSignalData}
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws FileDeleteException When failed upload cleanup cannot delete its tmp file
     */
    private function failFileUploadSession(string $reason): void
    {
        if (Hilos::$rt->selfConnection === null) {
            return;
        }

        Hilos::$fs->tmp[Hilos::$rt->selfConnection->fileSessionQuarantineBasename]->unlink();
        Hilos::$rt->selfConnection->actions->clearBinaryUploadSessionAndProgressUi();
        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_INVALID,
            Hilos::$rt->selfConnection->acceptKey,
            new FileUploadInvalidSignalData($reason),
        );
    }

    /**
     * Record a projected upload-progress notification at most every
     * {@see self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC} unless `$force` is true.
     *
     * The frontend projection sends the current `selfConnection` snapshot when this marker changes.
     *
     * @param bool $force When true, notify immediately (e.g. last chunk), bypassing the min-interval throttle
     * @throws RtActionsCollectionNameNullException When collection name is null for connection actions
     */
    private function noteFileUploadProgressProjectionThrottled(bool $force): void
    {
        if (Hilos::$rt->selfConnection === null) {
            return;
        }
        if (Hilos::$rt->selfConnection->fileProgressFilename === null) {
            return;
        }
        $uploaded = Hilos::$rt->selfConnection->fileProgressUploadedBytes;
        $total = Hilos::$rt->selfConnection->fileProgressTotalBytes;
        $isComplete = $total > 0 && $uploaded >= $total;
        $last = Hilos::$rt->selfConnection->uploadProgressLastSentAt;
        $elapsed = $last > 0.0 ? (microtime(true) - $last) : null;
        if (!$force && !$isComplete && $elapsed !== null && $elapsed < self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC) {
            return;
        }

        Hilos::$rt->selfConnection->actions->noteUploadProgressSentAt(microtime(true));
    }

    /**
     * Whether another in-flight upload, draft, or published attachment already uses this normalized name.
     *
     * @param string $normalized Output of {@see FileSystemHelper::normalizeBasename()}
     * @return bool True if the name collides with an active session or published attachment metadata
     */
    private function isFilenameInUse(string $normalized): bool
    {
        return Hilos::$rt->connections->hasActiveUploadWithNormalizedFilename($normalized)
            || Hilos::$rt->attachmentDrafts->hasDraftWithNormalizedFilename($normalized)
            || Hilos::$db->eventAttachments->hasPublishedFileWithNormalizedFilename($normalized);
    }
}
