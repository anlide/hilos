<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Main;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Router\DTO\FileModerationStateUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadAbortedSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadCompleteSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadInvalidSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadProgressUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadReadySignalData;
use Demo\Chat\Core\Router\DTO\FileUploadRejectedSignalData;
use Demo\Chat\Core\Router\DTO\ModerationFileRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationFileResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Helpers\FileSystemHelper;
use Random\RandomException;

/**
 * Main-page file upload init and file-moderation dismiss actions; uses {@see ChatAgent} for user-targeted signals.
 */
trait UploadFileTrait
{
    /**
     * Minimum wall-clock interval between {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE} sends when not forced.
     */
    private const float FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC = 0.3;

    /**
     * Handle {@see ChatSignalConstants::FILE_UPLOAD_INIT}: validate limits and filename, create tmp file,
     * start session, send {@see ChatSignalConstants::FILE_UPLOAD_READY}. Replaces an in-flight upload on the same socket.
     *
     * @param string $acceptKey WebSocket connection id
     * @throws RandomException If {@see random_bytes()} fails
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws RtTruthSourceWriteNotAllowedException When the truth source rejects a runtime write
     * @throws FileDeleteException When replacing an in-flight upload cannot delete its tmp file
     */
    protected function handleFileUploadInit(string $acceptKey, FileUploadInitActionDTO $dto): void
    {
        $agent = $this->getChatAgent();
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }

        if (Hilos::$rt->connections[$acceptKey]->fileSessionUploadId !== null) {
            Hilos::$fs->tmp[Hilos::$rt->connections[$acceptKey]->fileSessionQuarantineBasename]->unlink();
            Hilos::$rt->connections[$acceptKey]->actions->clearBinaryUploadSessionAndProgressUi();
            $this->logAgentInfo(
                "file upload aborted acceptKey={$acceptKey} reason=superseded_by_new_init",
            );
            $agent->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_ABORTED,
                $acceptKey,
                new FileUploadAbortedSignalData('superseded_by_new_init'),
            );
        }

        if (!$dto->isValid()) {
            $agent->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('invalid_payload', 'Invalid file metadata'),
            );

            return;
        }

        $maxFile = ChatSettingsHelper::getAttachmentMaxFileBytes();
        $maxTotal = ChatSettingsHelper::getAttachmentMaxTotalBytes();
        if ($dto->size > $maxFile) {
            $agent->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('size_limit', 'File exceeds maximum allowed size'),
            );

            return;
        }

        $publishedTotal = Hilos::$db->events->sumPublishedAttachmentBytes();
        $reserved = Hilos::$rt->connections->sumActiveUploadReservedBytes();
        if ($publishedTotal + $reserved + $dto->size > $maxTotal) {
            $agent->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('total_limit', 'Total attachment storage limit would be exceeded'),
            );

            return;
        }

        $norm = FileSystemHelper::normalizeBasename($dto->filename);
        if ($this->isFilenameInUse($norm)) {
            $agent->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('duplicate_filename', 'A file with this name already exists'),
            );

            return;
        }

        try {
            $tmpIndex = Hilos::$fs->tmp->create();
        } catch (FsException $e) {
            $this->logAgentError("Cannot create tmp file: {$e->getMessage()}");
            $agent->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('storage_error', 'Cannot start upload'),
            );

            return;
        }

        Hilos::$rt->connections[$acceptKey]->actions->beginBinaryFileUpload(
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

        $agent->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_READY,
            $acceptKey,
            new FileUploadReadySignalData(
                uploadId: $tmpIndex,
                filename: $dto->filename,
                mimeType: $dto->mimeType,
                size: $dto->size,
                clientUploadId: $dto->clientUploadId,
            ),
        );
        // Same moment as client seeds progress UI from READY (0 / size); avoids an immediate redundant progress_update.
        Hilos::$rt->connections[$acceptKey]->actions->noteUploadProgressSentAt(microtime(true));
    }

    /**
     * Handle {@see ChatSignalConstants::FILE_MODERATION_DISMISS}: clear rejected-phase banner when the user dismisses it.
     *
     * No-op if the connection is unknown or moderation phase is not `rejected`.
     *
     * @param string $acceptKey WebSocket connection id
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     */
    protected function handleFileModerationDismiss(string $acceptKey): void
    {
        $agent = $this->getChatAgent();
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }
        if (Hilos::$rt->connections[$acceptKey]->fileModPhase !== 'rejected') {
            return;
        }
        Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
        $agent->sendToUser(
            ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
            $acceptKey,
            new FileModerationStateUpdateSignalData(null),
        );
    }

    /**
     * Handle a websocket binary frame for an active main-page upload session.
     *
     * Appends the chunk to tmp storage, updates runtime progress, sends throttled progress updates,
     * and completes the upload when received bytes reach the declared size.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload and connection id
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws FileDeleteException When an invalid upload cannot delete its tmp file
     */
    protected function handleFileUploadBinaryFrame(WebSocketFrameBinarySignalDTO $data): void
    {
        $agent = $this->getChatAgent();
        $acceptKey = $data->acceptKey;
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            $this->logAgentInfo('frame_binary: unknown acceptKey, ignoring');

            return;
        }
        if (Hilos::$rt->connections[$acceptKey]->fileSessionUploadId === null) {
            $this->logAgentInfo(
                'frame_binary: no upload session acceptKey=' . $acceptKey
                . ' userId=' . Hilos::$rt->connections[$acceptKey]->userId,
            );
            $agent->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_INVALID,
                $acceptKey,
                new FileUploadInvalidSignalData('no_active_upload'),
            );

            return;
        }

        $len = strlen($data->payload);
        $declared = Hilos::$rt->connections[$acceptKey]->fileSessionDeclaredSize;
        $received = Hilos::$rt->connections[$acceptKey]->fileSessionReceivedBytes;
        if ($received + $len > $declared) {
            $this->logAgentError(
                'frame_binary: overflow acceptKey=' . $acceptKey
                . ' userId=' . Hilos::$rt->connections[$acceptKey]->userId,
            );
            $this->failFileUploadSession($acceptKey, 'size_overflow');

            return;
        }

        $tmpIndex = Hilos::$rt->connections[$acceptKey]->fileSessionQuarantineBasename;
        try {
            Hilos::$fs->tmp[$tmpIndex]->append($data->payload);
        } catch (FsException $e) {
            $this->logAgentError(
                'frame_binary: tmp append failed acceptKey=' . $acceptKey
                . ' userId=' . Hilos::$rt->connections[$acceptKey]->userId
                . ' error=' . $e->getMessage(),
            );
            $this->failFileUploadSession($acceptKey, 'write_error');

            return;
        }

        $newReceived = $received + $len;
        Hilos::$rt->connections[$acceptKey]->actions->applyStoredBinaryChunkProgress($newReceived);

        $this->sendFileUploadProgressUpdateThrottled($acceptKey, $newReceived === $declared);

        if ($newReceived === $declared) {
            $this->completeFileUpload($acceptKey);
        }
    }

    /**
     * Delete all attachment files on disk, reset file-related runtime fields on every connection, notify clients.
     *
     * Used from admin/cron cleanup so all tabs drop moderation and progress UI.
     */
    protected function deleteAllAttachmentFilesFromDisk(): void
    {
        $agent = $this->getChatAgent();

        Hilos::$fs->published->deleteAll();
        Hilos::$fs->quarantine->deleteAll();
        Hilos::$rt->connections->actions->clearAllFileRuntimeOnAllConnections();

        foreach (Hilos::$rt->connections as $connection) {
            $acceptKey = $connection->acceptKey;
            $agent->sendToUser(
                ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                $acceptKey,
                new FileModerationStateUpdateSignalData(null),
            );
            $agent->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
                $acceptKey,
                new FileUploadProgressUpdateSignalData(null),
            );
        }
    }

    /**
     * Apply {@see ChatSignalConstants::MODERATION_FILE_RESULT}: delete quarantine on reject, or move to published and
     * append a {@see ChatEventType::FILE_SHARED} event.
     *
     * Updates moderation UI on the uploader's socket only while {@see ModerationFileResultSignalData::$acceptKey} is
     * still registered and {@see ModerationFileResultSignalData::$userId} matches that connection.
     *
     * @throws HilosException On database, runtime, or signal failure
     */
    protected function handleModerationFileResult(ModerationFileResultSignalData $result): void
    {
        $agent = $this->getChatAgent();
        $storedName = $result->quarantineBasename;
        $quarantineFile = Hilos::$fs->quarantine[$storedName];
        $acceptKey = $result->acceptKey;
        $live = isset(Hilos::$rt->connections[$acceptKey])
            && Hilos::$rt->connections[$acceptKey]->userId === $result->userId;

        if (!$result->allow) {
            $quarantineFile->unlink();
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $this->logAgentError("File blocked by moderation (userId={$result->userId}; reason={$reason})");
            if ($live) {
                $connection = Hilos::$rt->connections[$acceptKey];
                $connection->actions->markFileModerationRejected(
                    $result->originalFilename,
                    $result->size,
                    $reason,
                );
                $agent->sendToUser(
                    ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                    $acceptKey,
                    new FileModerationStateUpdateSignalData($this->buildFileModerationStatePayload($connection)),
                );
            }

            return;
        }

        if (!$quarantineFile->exists()) {
            $this->logAgentError("Moderation allow but quarantine file missing: {$storedName}");
            if ($live) {
                Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
                $agent->sendToUser(
                    ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                    $acceptKey,
                    new FileModerationStateUpdateSignalData(null),
                );
            }

            return;
        }

        try {
            $quarantineFile->move('published');
        } catch (FsException $e) {
            $this->logAgentError("Failed to move file to published: {$e->getMessage()}");
            $quarantineFile->unlink();
            if ($live) {
                Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
                $agent->sendToUser(
                    ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                    $acceptKey,
                    new FileModerationStateUpdateSignalData(null),
                );
            }

            return;
        }

        if ($live) {
            Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
            $agent->sendToUser(
                ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                $acceptKey,
                new FileModerationStateUpdateSignalData(null),
            );
        }

        $token = pathinfo($storedName, PATHINFO_FILENAME);
        Hilos::$db->events->actions->addFile(
            userId: $result->userId,
            originalFilename: $result->originalFilename,
            mimeType: $result->mimeType,
            size: $result->size,
            downloadToken: $token,
            storedName: $storedName,
        );
    }

    /**
     * After the last binary chunk: move tmp to quarantine, clear upload session and progress UI,
     * enter `moderating` phase, send {@see ChatSignalConstants::FILE_UPLOAD_COMPLETE},
     * and dispatch {@see ChatSignalConstants::MODERATE_FILE_REQUEST} to the moderator agent.
     *
     * @param string $acceptKey WebSocket connection id
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws FileDeleteException When failed upload cleanup cannot delete its tmp file
     */
    private function completeFileUpload(string $acceptKey): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }
        $connection = Hilos::$rt->connections[$acceptKey];
        if ($connection->fileSessionUploadId === null) {
            return;
        }
        $agent = $this->getChatAgent();
        $uploadId = $connection->fileSessionUploadId;
        $tmpIndex = $connection->fileSessionQuarantineBasename;
        $declaredSize = $connection->fileSessionDeclaredSize;
        $originalFilename = $connection->fileSessionOriginalFilename;
        $mimeType = $connection->fileSessionMimeType;
        $userId = $connection->userId;

        $storedName = $uploadId . FsFile::extensionForMime($mimeType);
        try {
            Hilos::$fs->quarantine->createFromTmp($storedName, $tmpIndex);
        } catch (FsException $e) {
            $this->logAgentError("Cannot move tmp to quarantine: {$e->getMessage()}");
            $this->failFileUploadSession($acceptKey, 'storage_error');

            return;
        }

        $connection->actions->clearBinaryUploadSessionAndProgressUi();
        $agent->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
            $acceptKey,
            new FileUploadProgressUpdateSignalData(null),
        );

        $connection->actions->enterFileModerationPending($originalFilename, $declaredSize);
        $agent->sendToUser(
            ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
            $acceptKey,
            new FileModerationStateUpdateSignalData($this->buildFileModerationStatePayload($connection)),
        );

        $agent->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_COMPLETE,
            $acceptKey,
            new FileUploadCompleteSignalData(
                uploadId: $uploadId,
                filename: $originalFilename,
            ),
        );

        $agent->sendToAgent(
            ChatSignalConstants::MODERATE_FILE_REQUEST,
            new ModerationFileRequestSignalData(
                acceptKey: $acceptKey,
                userId: $userId,
                quarantineBasename: $storedName,
                originalFilename: $originalFilename,
                mimeType: $mimeType,
                size: $declaredSize,
                syntheticMessage: sprintf(
                    'User uploads a file for chat: name=%s, mime=%s, size=%d bytes. Approve only if appropriate for a public chat.',
                    $originalFilename,
                    $mimeType,
                    $declaredSize,
                ),
            ),
        );
    }

    /**
     * Abort the active upload: delete tmp file, clear session/progress runtime, notify the client.
     *
     * @param string $acceptKey WebSocket connection id
     * @param string $reason Short code forwarded in {@see FileUploadInvalidSignalData}
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws FileDeleteException When failed upload cleanup cannot delete its tmp file
     */
    private function failFileUploadSession(string $acceptKey, string $reason): void
    {
        Hilos::$fs->tmp[Hilos::$rt->connections[$acceptKey]->fileSessionQuarantineBasename]->unlink();
        Hilos::$rt->connections[$acceptKey]->actions->clearBinaryUploadSessionAndProgressUi();
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_INVALID,
            $acceptKey,
            new FileUploadInvalidSignalData($reason),
        );
    }

    /**
     * Send {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE} to this WebSocket connection at most every
     * {@see self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC} unless `$force` is true or the upload is complete
     * (`uploadedBytes` >= `totalBytes` > 0).
     *
     * Throttle clock is primed when {@see ChatSignalConstants::FILE_UPLOAD_READY} is sent (same baseline the client
     * uses for 0 / total). Reads progress from {@see RuntimeConnection::$fileProgressFilename} and related fields.
     *
     * @param string $acceptKey WebSocket connection id
     * @param bool $force When true, send immediately (e.g. last chunk), bypassing the min-interval throttle
     * @throws RtActionsCollectionNameNullException When collection name is null for connection actions
     */
    private function sendFileUploadProgressUpdateThrottled(string $acceptKey, bool $force): void
    {
        $agent = $this->getChatAgent();
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            $this->logAgentInfo(
                "upload_progress: throttle acceptKey={$acceptKey} abort_no_state",
            );

            return;
        }
        $progressName = Hilos::$rt->connections[$acceptKey]->fileProgressFilename;
        if ($progressName === null) {
            $this->logAgentInfo(
                "upload_progress: throttle acceptKey={$acceptKey} abort_no_progress_state",
            );

            return;
        }
        $uploaded = Hilos::$rt->connections[$acceptKey]->fileProgressUploadedBytes;
        $total = Hilos::$rt->connections[$acceptKey]->fileProgressTotalBytes;
        $isComplete = $total > 0 && $uploaded >= $total;
        $last = Hilos::$rt->connections[$acceptKey]->uploadProgressLastSentAt;
        $elapsed = $last > 0.0 ? (microtime(true) - $last) : null;
        if (!$force && !$isComplete && $elapsed !== null && $elapsed < self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC) {
            return;
        }

        Hilos::$rt->connections[$acceptKey]->actions->noteUploadProgressSentAt(microtime(true));
        $agent->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
            $acceptKey,
            new FileUploadProgressUpdateSignalData([
                'filename' => $progressName,
                'uploadedBytes' => $uploaded,
                'totalBytes' => $total,
            ]),
        );
    }

    /**
     * Build the frontend file moderation payload from the current connection state.
     *
     * @return ?array{phase: string, filename: string, uploadedBytes: int, totalBytes: int, reason: ?string, updatedAt: int}
     */
    private function buildFileModerationStatePayload(RuntimeConnection $connection): ?array
    {
        if ($connection->fileModPhase === null) {
            return null;
        }

        return [
            'phase' => $connection->fileModPhase,
            'filename' => $connection->fileModFilename,
            'uploadedBytes' => $connection->fileModUploadedBytes,
            'totalBytes' => $connection->fileModTotalBytes,
            'reason' => $connection->fileModReason !== '' ? $connection->fileModReason : null,
            'updatedAt' => $connection->fileModUpdatedAt,
        ];
    }

    /**
     * Whether another in-flight upload or an existing {@see ChatEventType::FILE_SHARED} event already uses this normalized name.
     *
     * @param string $normalized Output of {@see FileSystemHelper::normalizeBasename()}
     * @return bool True if the name collides with an active session or published attachment metadata
     */
    private function isFilenameInUse(string $normalized): bool
    {
        return Hilos::$rt->connections->hasActiveUploadWithNormalizedFilename($normalized)
            || Hilos::$db->events->hasPublishedFileWithNormalizedFilename($normalized);
    }
}
