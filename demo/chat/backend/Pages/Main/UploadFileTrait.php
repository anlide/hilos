<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Main;

use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
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

    private const string FILE_UPLOAD_FAILURE_CODE_INVALID_PAYLOAD = 'invalid_payload';
    private const string FILE_UPLOAD_FAILURE_CODE_SIZE_LIMIT = 'size_limit';
    private const string FILE_UPLOAD_FAILURE_CODE_TOTAL_LIMIT = 'total_limit';
    private const string FILE_UPLOAD_FAILURE_CODE_DUPLICATE_FILENAME = 'duplicate_filename';
    private const string FILE_UPLOAD_FAILURE_CODE_STORAGE_ERROR = 'storage_error';
    private const string FILE_UPLOAD_FAILURE_CODE_NO_ACTIVE_UPLOAD = 'no_active_upload';
    private const string FILE_UPLOAD_FAILURE_CODE_SIZE_OVERFLOW = 'size_overflow';
    private const string FILE_UPLOAD_FAILURE_CODE_WRITE_ERROR = 'write_error';

    /**
     * Handle file-upload init: validate limits and filename, create tmp file, and publish RT ready state.
     *
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the current outbound submit is being moderated or upload metadata lacks a client id
     * @throws DatabaseException When reading persisted attachment limit setting rows fails
     * @throws SettingException When attachment limit catalog keys are missing, read through the wrong type, or resolve to invalid values
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
        }

        if (!$dto->isValid()) {
            if ($dto->clientUploadId === null) {
                throw new ValidationException('Invalid file metadata');
            }
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                self::FILE_UPLOAD_FAILURE_CODE_INVALID_PAYLOAD,
                'Invalid file metadata',
            );

            return;
        }

        Hilos::$rt->attachmentDrafts->actions->deleteExpired();

        if ($dto->size > Hilos::$setting[ChatSettingsConstants::CHAT_ATTACHMENT_MAX_FILE_BYTES]->int()) {
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                self::FILE_UPLOAD_FAILURE_CODE_SIZE_LIMIT,
                'File exceeds maximum allowed size',
            );

            return;
        }

        if (
            Hilos::$db->eventAttachments->sumPublishedAttachmentBytes()
            + Hilos::$rt->connections->sumActiveUploadReservedBytes()
            + Hilos::$rt->attachmentDrafts->sumDraftBytes()
            + $dto->size > Hilos::$setting[ChatSettingsConstants::CHAT_ATTACHMENT_MAX_TOTAL_BYTES]->int()
        ) {
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                self::FILE_UPLOAD_FAILURE_CODE_TOTAL_LIMIT,
                'Total attachment storage limit would be exceeded',
            );

            return;
        }

        $norm = FileSystemHelper::normalizeBasename($dto->filename);
        if (
            Hilos::$rt->connections->hasActiveUploadWithNormalizedFilename($norm)
            || Hilos::$rt->attachmentDrafts->hasDraftWithNormalizedFilename($norm)
            || Hilos::$db->eventAttachments->hasPublishedFileWithNormalizedFilename($norm)
        ) {
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                self::FILE_UPLOAD_FAILURE_CODE_DUPLICATE_FILENAME,
                'A file with this name already exists',
            );

            return;
        }

        try {
            $tmpIndex = Hilos::$fs->tmp->create();
        } catch (FsException $e) {
            $this->logAgentError("Cannot create tmp file: {$e->getMessage()}");
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                $dto->clientUploadId,
                self::FILE_UPLOAD_FAILURE_CODE_STORAGE_ERROR,
                'Cannot start upload',
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
    }

    /**
     * Handle a websocket binary frame for an active main-page upload session.
     *
     * Appends the chunk to tmp storage, updates runtime progress, records throttled projection markers,
     * and completes the upload when received bytes reach the declared size.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload and connection id
     * @throws FileDeleteException When upload cleanup cannot delete its tmp file
     * @throws RtActionsCallbackNotSetException When attachment draft runtime item creation is not configured
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws RtActionsStateCollectionNullException When attachment draft runtime state is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the truth source rejects a runtime write
     */
    protected function handleFileUploadBinaryFrame(WebSocketFrameBinarySignalDTO $data): void
    {
        if (Hilos::$rt->selfConnection === null) {
            return;
        }
        if (Hilos::$rt->selfConnection->fileSessionUploadId === null) {
            Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
                Hilos::$rt->selfConnection->fileUploadClientUploadId,
                self::FILE_UPLOAD_FAILURE_CODE_NO_ACTIVE_UPLOAD,
                $this->fileUploadFailureMessage(self::FILE_UPLOAD_FAILURE_CODE_NO_ACTIVE_UPLOAD),
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
            $this->failFileUploadSession(self::FILE_UPLOAD_FAILURE_CODE_SIZE_OVERFLOW);

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
            $this->failFileUploadSession(self::FILE_UPLOAD_FAILURE_CODE_WRITE_ERROR);

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
     *
     * @throws RtActionsCollectionNameNullException When runtime actions collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the truth source rejects a runtime write
     * @throws FileDeleteException When deleting published or quarantine attachment files fails
     */
    protected function deleteAllAttachmentFilesFromDisk(): void
    {
        Hilos::$fs->published->deleteAll();
        Hilos::$fs->quarantine->deleteAll();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$rt->connections->actions->clearAllFileRuntimeOnAllConnections();
    }

    /**
     * After the last binary chunk: move tmp to quarantine, clear upload state, and create an attachment draft.
     *
     * @throws FileDeleteException When storage-error cleanup cannot delete the tmp file
     * @throws RtActionsCallbackNotSetException When attachment draft runtime item creation is not configured
     * @throws RtActionsCollectionNameNullException When runtime actions collection name is unavailable
     * @throws RtActionsStateCollectionNullException When attachment draft runtime state is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the truth source rejects a runtime write
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
            $this->failFileUploadSession(self::FILE_UPLOAD_FAILURE_CODE_STORAGE_ERROR);

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
    }

    /**
     * Abort the active upload: delete tmp file, clear session/progress runtime, and expose failure state.
     *
     * @param string $code One of self::FILE_UPLOAD_FAILURE_CODE_* constants
     * @throws FileDeleteException When the tmp file cannot be deleted
     * @throws RtActionsCollectionNameNullException When runtime actions collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the truth source rejects a runtime write
     */
    private function failFileUploadSession(string $code): void
    {
        if (Hilos::$rt->selfConnection === null) {
            return;
        }

        $clientUploadId = Hilos::$rt->selfConnection->fileSessionClientUploadId !== ''
            ? Hilos::$rt->selfConnection->fileSessionClientUploadId
            : Hilos::$rt->selfConnection->fileUploadClientUploadId;
        Hilos::$fs->tmp[Hilos::$rt->selfConnection->fileSessionQuarantineBasename]->unlink();
        Hilos::$rt->selfConnection->actions->failBinaryFileUpload(
            $clientUploadId,
            $code,
            $this->fileUploadFailureMessage($code),
        );
    }

    /**
     * Resolve a user-facing message for an upload failure code exposed through self-connection state.
     *
     * @param string $code One of self::FILE_UPLOAD_FAILURE_CODE_* constants
     * @return string User-facing upload failure message
     */
    private function fileUploadFailureMessage(string $code): string
    {
        return match ($code) {
            self::FILE_UPLOAD_FAILURE_CODE_NO_ACTIVE_UPLOAD => 'No active upload',
            self::FILE_UPLOAD_FAILURE_CODE_SIZE_OVERFLOW => 'Uploaded data exceeds declared size',
            self::FILE_UPLOAD_FAILURE_CODE_WRITE_ERROR => 'Cannot store upload data',
            self::FILE_UPLOAD_FAILURE_CODE_STORAGE_ERROR => 'Cannot finish upload',
            default => 'Upload failed',
        };
    }

    /**
     * Record a projected upload-progress notification at most once per configured interval unless forced.
     *
     * The frontend projection sends the current `selfConnection` snapshot when this marker changes.
     *
     * @param bool $force When true, notify immediately (e.g. last chunk), bypassing the min-interval throttle
     * @throws RtActionsCollectionNameNullException When runtime actions collection name is unavailable
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

        Hilos::$rt->selfConnection->actions->noteUploadProgressSentAt();
    }
}
