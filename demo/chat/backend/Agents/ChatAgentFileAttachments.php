<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\FileModerationStateUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadAbortedSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadProgressUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadCompleteSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadInvalidSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadReadySignalData;
use Demo\Chat\Core\Router\DTO\FileUploadRejectedSignalData;
use Demo\Chat\Core\Router\DTO\ModerationFileRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationFileResultSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Logger;
use Hilos\Utils\Helpers\FileSystemHelper;
use Random\RandomException;

/**
 * File attachments: binary WebSocket upload, quarantine disk storage, async moderation, and per-connection UI signals.
 *
 * Connection-scoped state is read and written only via {@see Hilos::$rt->connections}[`$acceptKey`] (no cached
 * {@see RuntimeConnection} items across mutations). Per-socket writes use {@see RuntimeConnection::$actions};
 * collection-level helpers remain on {@see \Demo\Chat\Runtime\View\Actions\Collection\ConnectionsActions} (register,
 * unregister, clear, bulk file reset). User-targeted signals use the owning connection `acceptKey` only.
 */
trait ChatAgentFileAttachments
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
     */
    public function handleFileUploadInit(string $acceptKey, FileUploadInitActionDTO $dto): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }

        if (Hilos::$rt->connections[$acceptKey]->fileSessionUploadId !== null) {
            Hilos::$fs->tmp[Hilos::$rt->connections[$acceptKey]->fileSessionQuarantineBasename]->unlink();
            Hilos::$rt->connections[$acceptKey]->actions->clearBinaryUploadSessionAndProgressUi();
            Logger::logAgentInfo(
                $this->getId(),
                "file upload aborted acceptKey={$acceptKey} reason=superseded_by_new_init",
            );
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_ABORTED,
                $acceptKey,
                new FileUploadAbortedSignalData('superseded_by_new_init'),
            );
        }

        if (!$dto->isValid()) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('invalid_payload', 'Invalid file metadata'),
            );

            return;
        }

        $maxFile = ChatSettingsHelper::getAttachmentMaxFileBytes();
        $maxTotal = ChatSettingsHelper::getAttachmentMaxTotalBytes();
        if ($dto->size > $maxFile) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('size_limit', 'File exceeds maximum allowed size'),
            );

            return;
        }

        $publishedTotal = Hilos::$db->events->sumPublishedAttachmentBytes();
        $reserved = Hilos::$rt->connections->sumActiveUploadReservedBytes();
        if ($publishedTotal + $reserved + $dto->size > $maxTotal) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('total_limit', 'Total attachment storage limit would be exceeded'),
            );

            return;
        }

        $norm = FileSystemHelper::normalizeBasename($dto->filename);
        if ($this->isFilenameInUse($norm)) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('duplicate_filename', 'A file with this name already exists'),
            );

            return;
        }

        try {
            $tmpIndex = Hilos::$fs->tmp->create();
        } catch (FsException $e) {
            Logger::logAgentError($this->getId(), "Cannot create tmp file: {$e->getMessage()}");
            $this->sendToUser(
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

        $this->sendToUser(
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
     * @throws RtActionsCollectionNameNullException
     */
    public function handleFileModerationDismiss(string $acceptKey): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }
        if (Hilos::$rt->connections[$acceptKey]->fileModPhase !== 'rejected') {
            return;
        }
        Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
        $this->sendToUser(
            ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
            $acceptKey,
            new FileModerationStateUpdateSignalData(null),
        );
    }

    /**
     * WebSocket binary frame handler: append chunk to the quarantine file, update received bytes, optionally emit
     * throttled {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE}, then finalize when size matches declared total.
     *
     * @param WebSocketFrameBinarySignalDTO $data Frame payload and {@see WebSocketFrameBinarySignalDTO::$acceptKey}
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $acceptKey = $data->acceptKey;
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentInfo($this->getId(), 'frame_binary: unknown acceptKey, ignoring');

            return;
        }
        if (Hilos::$rt->connections[$acceptKey]->fileSessionUploadId === null) {
            Logger::logAgentInfo(
                $this->getId(),
                'frame_binary: no upload session acceptKey=' . $acceptKey
                . ' userId=' . Hilos::$rt->connections[$acceptKey]->userId,
            );
            $this->sendToUser(
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
            Logger::logAgentError(
                $this->getId(),
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
            Logger::logAgentError(
                $this->getId(),
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
     * Used from admin/cron cleanup ({@see ChatAgent::onSignalCron}) so all tabs drop moderation and progress UI.
     */
    public function deleteAllAttachmentFilesFromDisk(): void
    {
        Hilos::$fs->published->deleteAll();
        Hilos::$fs->quarantine->deleteAll();
        Hilos::$rt->connections->actions->clearAllFileRuntimeOnAllConnections();

        foreach (Hilos::$rt->connections as $conn) {
            $ak = $conn->acceptKey;
            $this->sendToUser(
                ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                $ak,
                new FileModerationStateUpdateSignalData(null),
            );
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
                $ak,
                new FileUploadProgressUpdateSignalData(null),
            );
        }
    }

    /**
     * After the last binary chunk: move tmp → quarantine, clear upload session and progress UI,
     * enter `moderating` phase, send {@see ChatSignalConstants::FILE_UPLOAD_COMPLETE},
     * and dispatch {@see ChatSignalConstants::MODERATE_FILE_REQUEST} to the moderator agent.
     *
     * @param string $acceptKey WebSocket connection id
     * @throws RtActionsCollectionNameNullException
     */
    private function completeFileUpload(string $acceptKey): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }
        $conn = Hilos::$rt->connections[$acceptKey];
        if ($conn->fileSessionUploadId === null) {
            return;
        }
        $uploadId = $conn->fileSessionUploadId;
        $tmpIndex = $conn->fileSessionQuarantineBasename;
        $declaredSize = $conn->fileSessionDeclaredSize;
        $originalFilename = $conn->fileSessionOriginalFilename;
        $mimeType = $conn->fileSessionMimeType;
        $userId = $conn->userId;

        $ext = FsFile::extensionForMime($mimeType);
        $storedName = $uploadId . $ext;
        try {
            Hilos::$fs->quarantine->createFromTmp($storedName, $tmpIndex);
        } catch (FsException $e) {
            Logger::logAgentError($this->getId(), "Cannot move tmp to quarantine: {$e->getMessage()}");
            $this->failFileUploadSession($acceptKey, 'storage_error');

            return;
        }

        $conn->actions->clearBinaryUploadSessionAndProgressUi();
        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
            $acceptKey,
            new FileUploadProgressUpdateSignalData(null),
        );

        $conn->actions->enterFileModerationPending($originalFilename, $declaredSize);
        $modPhase = $conn->fileModPhase;
        $this->sendToUser(
            ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
            $acceptKey,
            new FileModerationStateUpdateSignalData(
                $modPhase === null ? null : [
                    'phase' => $modPhase,
                    'filename' => $conn->fileModFilename,
                    'uploadedBytes' => $conn->fileModUploadedBytes,
                    'totalBytes' => $conn->fileModTotalBytes,
                    'reason' => $conn->fileModReason !== '' ? $conn->fileModReason : null,
                    'updatedAt' => $conn->fileModUpdatedAt,
                ],
            ),
        );

        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_COMPLETE,
            $acceptKey,
            new FileUploadCompleteSignalData(
                uploadId: $uploadId,
                filename: $originalFilename,
            ),
        );

        $synthetic = sprintf(
            'User uploads a file for chat: name=%s, mime=%s, size=%d bytes. Approve only if appropriate for a public chat.',
            $originalFilename,
            $mimeType,
            $declaredSize,
        );

        $this->sendToAgent(
            ChatSignalConstants::MODERATE_FILE_REQUEST,
            new ModerationFileRequestSignalData(
                acceptKey: $acceptKey,
                userId: $userId,
                quarantineBasename: $storedName,
                originalFilename: $originalFilename,
                mimeType: $mimeType,
                size: $declaredSize,
                syntheticMessage: $synthetic,
            ),
        );
    }

    /**
     * Apply {@see ChatSignalConstants::MODERATION_FILE_RESULT}: delete quarantine on reject, or move to published and append a {@see ChatEventType::FILE_SHARED} event.
     *
     * Updates moderation UI on the uploader's socket only while {@see ModerationFileResultSignalData::$acceptKey} is still
     * registered and {@see ModerationFileResultSignalData::$userId} matches that connection.
     *
     * @throws HilosException
     */
    public function handleModerationFileResult(ModerationFileResultSignalData $result): void
    {
        $storedName = $result->quarantineBasename;
        $quarantineFile = Hilos::$fs->quarantine[$storedName];
        $acceptKey = $result->acceptKey;
        $live = isset(Hilos::$rt->connections[$acceptKey])
            && Hilos::$rt->connections[$acceptKey]->userId === $result->userId;

        if (!$result->allow) {
            $quarantineFile->unlink();
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            Logger::logAgentError($this->getId(), "File blocked by moderation (userId={$result->userId}; reason={$reason})");
            if ($live) {
                $rejConn = Hilos::$rt->connections[$acceptKey];
                $rejConn->actions->markFileModerationRejected(
                    $result->originalFilename,
                    $result->size,
                    $reason,
                );
                $rejPhase = $rejConn->fileModPhase;
                $this->sendToUser(
                    ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                    $acceptKey,
                    new FileModerationStateUpdateSignalData(
                        $rejPhase === null ? null : [
                            'phase' => $rejPhase,
                            'filename' => $rejConn->fileModFilename,
                            'uploadedBytes' => $rejConn->fileModUploadedBytes,
                            'totalBytes' => $rejConn->fileModTotalBytes,
                            'reason' => $rejConn->fileModReason !== '' ? $rejConn->fileModReason : null,
                            'updatedAt' => $rejConn->fileModUpdatedAt,
                        ],
                    ),
                );
            }

            return;
        }

        if (!$quarantineFile->exists()) {
            Logger::logAgentError($this->getId(), "Moderation allow but quarantine file missing: {$storedName}");
            if ($live) {
                Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
                $this->sendToUser(
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
            Logger::logAgentError($this->getId(), "Failed to move file to published: {$e->getMessage()}");
            $quarantineFile->unlink();
            if ($live) {
                Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
                $this->sendToUser(
                    ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                    $acceptKey,
                    new FileModerationStateUpdateSignalData(null),
                );
            }

            return;
        }

        if ($live) {
            Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
            $this->sendToUser(
                ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                $acceptKey,
                new FileModerationStateUpdateSignalData(null),
            );
        }

        $token = pathinfo($storedName, PATHINFO_FILENAME);
        $event = Hilos::$db->events->actions->add(ChatEventType::FILE_SHARED->value, $result->userId, null, [
            'originalFilename' => $result->originalFilename,
            'mimeType' => $result->mimeType,
            'size' => $result->size,
            'downloadToken' => $token,
            'storedName' => $storedName,
        ]);

        $this->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(full: [DbChatContext::events => Events::fromSingleItem($event)])),
        );
    }

    /**
     * Abort the active upload: delete tmp file, clear session/progress runtime, notify the client.
     *
     * @param string $acceptKey WebSocket connection id
     * @param string $reason Short code forwarded in {@see FileUploadInvalidSignalData}
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     */
    private function failFileUploadSession(string $acceptKey, string $reason): void
    {
        Hilos::$fs->tmp[Hilos::$rt->connections[$acceptKey]->fileSessionQuarantineBasename]->unlink();
        Hilos::$rt->connections[$acceptKey]->actions->clearBinaryUploadSessionAndProgressUi();
        $this->sendToUser(
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
     * @throws RtActionsCollectionNameNullException When collection name is null for connection actions.
     */
    private function sendFileUploadProgressUpdateThrottled(string $acceptKey, bool $force): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: throttle acceptKey={$acceptKey} abort_no_state",
            );

            return;
        }
        $progressName = Hilos::$rt->connections[$acceptKey]->fileProgressFilename;
        if ($progressName === null) {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: throttle acceptKey={$acceptKey} abort_no_progress_state",
            );

            return;
        }
        $uploaded = Hilos::$rt->connections[$acceptKey]->fileProgressUploadedBytes;
        $total = Hilos::$rt->connections[$acceptKey]->fileProgressTotalBytes;
        $isComplete = $total > 0 && $uploaded >= $total;
        $now = microtime(true);
        $last = Hilos::$rt->connections[$acceptKey]->uploadProgressLastSentAt;
        $elapsed = $last > 0.0 ? ($now - $last) : null;
        $minSec = self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC;
        if (!$force && !$isComplete && $elapsed !== null && $elapsed < $minSec) {
            return;
        }
        Hilos::$rt->connections[$acceptKey]->actions->noteUploadProgressSentAt($now);
        $this->sendToUser(
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
