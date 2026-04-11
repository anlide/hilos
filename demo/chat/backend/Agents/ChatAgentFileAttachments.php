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
use Demo\Chat\Utils\ChatAttachmentStorage;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Logger;
use Random\RandomException;

/**
 * File upload (WebSocket action + binary frames), quarantine storage, async file moderation, and per-connection UI state.
 *
 * Reads connection file state only as {@see Hilos::$rt->connections}[`$acceptKey`] (no local RtItem variables). Mutations use {@see \Demo\Chat\Runtime\View\Actions\Collection\ConnectionsActions} named methods. Signals go to the owning `acceptKey` only.
 */
trait ChatAgentFileAttachments
{
    /** Minimum interval between non-forced {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE} sends. */
    private const float FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC = 0.3;

    /**
     * Start or replace a binary upload on this connection (validates limits, creates quarantine .part file).
     *
     * @param string $acceptKey WebSocket connection id
     * @throws RandomException If {@see random_bytes()} fails.
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function handleFileUploadInit(string $acceptKey, FileUploadInitActionDTO $dto): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }

        if (Hilos::$rt->connections[$acceptKey]->fileSessionUploadId !== null) {
            ChatAttachmentStorage::deleteIfExists(
                ChatAttachmentStorage::quarantinePathForBasename(
                    Hilos::$rt->connections[$acceptKey]->fileSessionQuarantineBasename,
                ),
            );
            Hilos::$rt->connections->actions->abortFileUploadClearSessionAndProgress($acceptKey);
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

        $norm = $this->normalizeFilename($dto->filename);
        if ($this->isFilenameInUse($norm)) {
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('duplicate_filename', 'A file with this name already exists'),
            );

            return;
        }

        $uploadId = bin2hex(random_bytes(16));
        $quarantineBasename = $uploadId . '.part';
        $path = ChatAttachmentStorage::quarantinePathForBasename($quarantineBasename);
        if (file_put_contents($path, '') === false) {
            Logger::logAgentError($this->getId(), "Cannot create quarantine file: {$path}");
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('storage_error', 'Cannot start upload'),
            );

            return;
        }

        Hilos::$rt->connections[$acceptKey]->actions->beginBinaryFileUpload(
            $uploadId,
            $dto->size,
            $quarantineBasename,
            $dto->filename,
            $dto->mimeType,
            $dto->clientUploadId,
            $norm,
            $dto->filename,
            $dto->size,
        );

        Logger::logAgentInfo(
            $this->getId(),
            sprintf(
                'upload_progress: after_init acceptKey=%s userId=%d totalBytes=%d (force send)',
                $acceptKey,
                Hilos::$rt->connections[$acceptKey]->userId,
                $dto->size,
            ),
        );
        $this->broadcastUploadProgressThrottled($acceptKey, true);

        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_READY,
            $acceptKey,
            new FileUploadReadySignalData(
                uploadId: $uploadId,
                filename: $dto->filename,
                mimeType: $dto->mimeType,
                size: $dto->size,
                clientUploadId: $dto->clientUploadId,
            ),
        );
    }

    /**
     * Clear rejected-file moderation banner on this connection (no-op if not in rejected phase).
     *
     * @param string $acceptKey WebSocket connection id
     */
    public function handleFileModerationDismiss(string $acceptKey): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }
        $payload = $this->getFileModerationUiPayloadForAcceptKey($acceptKey);
        if ($payload === null || ($payload['phase'] ?? '') !== 'rejected') {
            return;
        }
        Hilos::$rt->connections->actions->clearFileModerationBannerAfterDismiss($acceptKey);
        $this->sendFileModerationStateUpdate($acceptKey, null);
    }

    /**
     * Append binary payload to the active upload session for {@see WebSocketFrameBinarySignalDTO::$acceptKey}.
     *
     * @param string $source Signal source identifier (framework)
     * @param string $name Signal name (framework)
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $acceptKey = $data->acceptKey;
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentInfo($this->getId(), 'frame_binary: unknown acceptKey, ignoring');

            return;
        }
        $conn = Hilos::$rt->connections[$acceptKey];
        $userId = $conn->userId;
        $session = $this->fileUploadSessionArrayFromConnection($conn);
        if ($session === null) {
            Logger::logAgentInfo($this->getId(), "frame_binary: no upload session acceptKey={$acceptKey} userId={$userId}");
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_INVALID,
                $acceptKey,
                new FileUploadInvalidSignalData('no_active_upload'),
            );

            return;
        }

        $chunk = $data->payload;
        $len = strlen($chunk);
        $declared = (int)$session['declaredSize'];
        $received = (int)$session['receivedBytes'];
        if ($received + $len > $declared) {
            Logger::logAgentError($this->getId(), "frame_binary: overflow acceptKey={$acceptKey} userId={$userId}");
            $this->failFileUploadSession($acceptKey, 'size_overflow');

            return;
        }

        $path = ChatAttachmentStorage::quarantinePathForBasename((string)$session['quarantineBasename']);
        if (file_put_contents($path, $chunk, FILE_APPEND) === false) {
            $this->failFileUploadSession($acceptKey, 'write_error');

            return;
        }

        $newReceived = $received + $len;
        Hilos::$rt->connections->actions->applyStoredBinaryChunkProgress($acceptKey, $newReceived);

        $conn = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($conn === null || $conn->fileSessionUploadId === null) {
            return;
        }

        if ($this->getFileUploadProgressPayloadForAcceptKey($acceptKey) !== null) {
            $force = $newReceived === $declared;
            Logger::logAgentInfo(
                $this->getId(),
                sprintf(
                    'upload_progress: binary_chunk acceptKey=%s chunkBytes=%d receivedAfter=%d declared=%d forceLastChunk=%s',
                    $acceptKey,
                    $len,
                    $newReceived,
                    $declared,
                    $force ? '1' : '0',
                ),
            );
            $this->broadcastUploadProgressThrottled($acceptKey, $force);
        } else {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: binary_chunk acceptKey={$acceptKey} skipped_no_progress_map (session exists)",
            );
        }

        if ($newReceived === $declared) {
            $this->completeFileUpload($acceptKey);
        }
    }

    /**
     * File moderation UI payload for handshake/subscribe, or null if no file moderation state on this socket.
     *
     * @param string $acceptKey WebSocket connection id
     * @return ?array<string, mixed>
     */
    public function getFileModerationUiPayloadForAcceptKey(string $acceptKey): ?array
    {
        $conn = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($conn === null) {
            return null;
        }
        $phase = $conn->fileModPhase;
        if ($phase === null) {
            return null;
        }
        $reason = $conn->fileModReason;

        return [
            'phase' => $phase,
            'filename' => $conn->fileModFilename,
            'uploadedBytes' => $conn->fileModUploadedBytes,
            'totalBytes' => $conn->fileModTotalBytes,
            'reason' => is_string($reason) && $reason !== '' ? $reason : null,
            'updatedAt' => $conn->fileModUpdatedAt,
        ];
    }

    /**
     * In-flight upload progress for handshake/subscribe, or null if no progress bar state on this socket.
     *
     * @param string $acceptKey WebSocket connection id
     * @return ?array<string, mixed>
     */
    public function getFileUploadProgressPayloadForAcceptKey(string $acceptKey): ?array
    {
        $conn = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($conn === null || $conn->fileProgressFilename === null) {
            return null;
        }

        return [
            'filename' => $conn->fileProgressFilename,
            'uploadedBytes' => $conn->fileProgressUploadedBytes,
            'totalBytes' => $conn->fileProgressTotalBytes,
        ];
    }

    /**
     * Binary upload session map for handler logic, or null when {@see RuntimeConnection::$fileSessionUploadId} is unset.
     *
     * @return ?array<string, mixed>
     */
    private function fileUploadSessionArrayFromConnection(RuntimeConnection $conn): ?array
    {
        if ($conn->fileSessionUploadId === null) {
            return null;
        }

        return [
            'uploadId' => $conn->fileSessionUploadId,
            'declaredSize' => $conn->fileSessionDeclaredSize,
            'receivedBytes' => $conn->fileSessionReceivedBytes,
            'quarantineBasename' => $conn->fileSessionQuarantineBasename,
            'originalFilename' => $conn->fileSessionOriginalFilename,
            'mimeType' => $conn->fileSessionMimeType,
            'clientUploadId' => $conn->fileSessionClientUploadId,
            'normalizedFilename' => $conn->fileSessionNormalizedFilename,
        ];
    }

    /**
     * Wipe published and quarantine dirs, clear file runtime on every connection, notify clients (admin/cron path).
     */
    public function deleteAllAttachmentFilesFromDisk(): void
    {
        $published = ChatAttachmentStorage::publishedDir();
        $quarantine = ChatAttachmentStorage::quarantineDir();
        $this->deleteFilesInDir($published);
        $this->deleteFilesInDir($quarantine);
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
     * Finalize upload: clear session/progress, enter moderating UI, notify client, send request to ModeratorAgent.
     *
     * @param string $acceptKey WebSocket connection id
     */
    private function completeFileUpload(string $acceptKey): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }
        $conn = Hilos::$rt->connections[$acceptKey];
        $session = $this->fileUploadSessionArrayFromConnection($conn);
        if ($session === null) {
            return;
        }

        Hilos::$rt->connections->actions->clearFileUploadSessionAfterReceiveComplete($acceptKey);
        $this->sendFileUploadProgress($acceptKey, null);

        Hilos::$rt->connections->actions->enterFileModerationPending(
            $acceptKey,
            (string)$session['originalFilename'],
            (int)$session['declaredSize'],
        );
        $this->sendFileModerationStateUpdate($acceptKey, $this->getFileModerationUiPayloadForAcceptKey($acceptKey));

        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_COMPLETE,
            $acceptKey,
            new FileUploadCompleteSignalData(
                uploadId: (string)$session['uploadId'],
                filename: (string)$session['originalFilename'],
            ),
        );

        $synthetic = sprintf(
            'User uploads a file for chat: name=%s, mime=%s, size=%d bytes. Approve only if appropriate for a public chat.',
            (string)$session['originalFilename'],
            (string)$session['mimeType'],
            (int)$session['declaredSize'],
        );

        $this->sendToAgent(
            ChatSignalConstants::MODERATE_FILE_REQUEST,
            new ModerationFileRequestSignalData(
                acceptKey: $acceptKey,
                userId: $conn->userId,
                quarantineBasename: (string)$session['quarantineBasename'],
                originalFilename: (string)$session['originalFilename'],
                mimeType: (string)$session['mimeType'],
                size: (int)$session['declaredSize'],
                syntheticMessage: $synthetic,
            ),
        );
    }

    /**
     * Apply ModeratorAgent outcome: reject (delete quarantine) or publish/move to published and broadcast {@see ChatSignalConstants::NEW_EVENT}.
     *
     * Updates file moderation UI only if the target connection is still open and {@see ModerationFileResultSignalData::$userId} matches.
     */
    public function handleModerationFileResult(ModerationFileResultSignalData $result): void
    {
        $path = ChatAttachmentStorage::quarantinePathForBasename($result->quarantineBasename);
        $acceptKey = $result->acceptKey;
        $live = $this->isModerationTargetConnectionLive($result);

        if (!$result->allow) {
            ChatAttachmentStorage::deleteIfExists($path);
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            Logger::logAgentError($this->getId(), "File blocked by moderation (userId={$result->userId}; reason={$reason})");
            if ($live) {
                Hilos::$rt->connections->actions->markFileModerationRejected(
                    $acceptKey,
                    $result->originalFilename,
                    $result->size,
                    $reason,
                );
                $this->sendFileModerationStateUpdate($acceptKey, $this->getFileModerationUiPayloadForAcceptKey($acceptKey));
            }

            return;
        }

        if (!is_file($path)) {
            Logger::logAgentError($this->getId(), "Moderation allow but quarantine file missing: {$result->quarantineBasename}");
            if ($live) {
                Hilos::$rt->connections->actions->clearFileModerationBannerAfterAllowMissingQuarantine($acceptKey);
                $this->sendFileModerationStateUpdate($acceptKey, null);
            }

            return;
        }

        $token = bin2hex(random_bytes(16));
        $ext = ChatAttachmentStorage::extensionForMime($result->mimeType);
        $storedName = $token . $ext;

        if (!ChatAttachmentStorage::moveToPublished($path, $storedName)) {
            Logger::logAgentError($this->getId(), 'Failed to move file to published storage');
            ChatAttachmentStorage::deleteIfExists($path);
            if ($live) {
                Hilos::$rt->connections->actions->clearFileModerationBannerAfterPublishFailed($acceptKey);
                $this->sendFileModerationStateUpdate($acceptKey, null);
            }

            return;
        }

        if ($live) {
            Hilos::$rt->connections->actions->clearFileModerationBannerAfterPublishSuccess($acceptKey);
            $this->sendFileModerationStateUpdate($acceptKey, null);
        }

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
     * True if the connection from the moderation result is still registered and belongs to the same user.
     */
    private function isModerationTargetConnectionLive(ModerationFileResultSignalData $result): bool
    {
        if (!isset(Hilos::$rt->connections[$result->acceptKey])) {
            return false;
        }

        return Hilos::$rt->connections[$result->acceptKey]->userId === $result->userId;
    }

    /**
     * Abort upload and send {@see ChatSignalConstants::FILE_UPLOAD_INVALID} to this connection.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    private function failFileUploadSession(string $acceptKey, string $reason): void
    {
        $conn = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($conn !== null && $conn->fileSessionUploadId !== null) {
            ChatAttachmentStorage::deleteIfExists(
                ChatAttachmentStorage::quarantinePathForBasename($conn->fileSessionQuarantineBasename),
            );
        }
        Hilos::$rt->connections->actions->abortFileUploadClearSessionAndProgress($acceptKey);
        Logger::logAgentInfo($this->getId(), "file upload aborted acceptKey={$acceptKey} reason={$reason}");
        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_INVALID,
            $acceptKey,
            new FileUploadInvalidSignalData($reason),
        );
    }

    /**
     * @param ?array<string, mixed> $payload UI state or null to clear
     */
    private function sendFileModerationStateUpdate(string $acceptKey, ?array $payload): void
    {
        $this->sendToUser(
            ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
            $acceptKey,
            new FileModerationStateUpdateSignalData($payload),
        );
    }

    /**
     * @param ?array<string, mixed> $payload Progress map or null to clear
     */
    private function sendFileUploadProgress(string $acceptKey, ?array $payload): void
    {
        if ($payload !== null) {
            $up = (int)($payload['uploadedBytes'] ?? 0);
            $tot = (int)($payload['totalBytes'] ?? 0);
            Logger::logAgentInfo(
                $this->getId(),
                sprintf(
                    'upload_progress: ws_send FILE_UPLOAD_PROGRESS_UPDATE acceptKey=%s uploaded=%d/%d',
                    $acceptKey,
                    $up,
                    $tot,
                ),
            );
        } else {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: ws_send FILE_UPLOAD_PROGRESS_UPDATE acceptKey={$acceptKey} payload=null",
            );
        }
        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
            $acceptKey,
            new FileUploadProgressUpdateSignalData($payload),
        );
    }

    /**
     * Send throttled {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE} to this connection unless interval not elapsed.
     *
     * @param bool $force When true, send immediately (subscribe, first progress after init, or final chunk)
     */
    private function broadcastUploadProgressThrottled(string $acceptKey, bool $force): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: throttle acceptKey={$acceptKey} abort_no_state",
            );

            return;
        }
        $progress = $this->getFileUploadProgressPayloadForAcceptKey($acceptKey);
        if ($progress === null) {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: throttle acceptKey={$acceptKey} abort_no_progress_state",
            );

            return;
        }
        $uploaded = (int)($progress['uploadedBytes'] ?? 0);
        $total = (int)($progress['totalBytes'] ?? 0);
        $isComplete = $total > 0 && $uploaded >= $total;
        $now = microtime(true);
        $last = (float)Hilos::$rt->connections[$acceptKey]->uploadProgressLastSentAt;
        $elapsed = $last > 0.0 ? ($now - $last) : null;
        $elapsedLabel = $elapsed === null ? 'n/a_first_send' : sprintf('%.4fs', $elapsed);
        $minSec = self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC;
        if (!$force && !$isComplete && $elapsed !== null && $elapsed < $minSec) {
            Logger::logAgentInfo(
                $this->getId(),
                sprintf(
                    'upload_progress: throttle SKIP acceptKey=%s uploaded=%d/%d elapsedSinceLastSend=%s need>=%.2fs force=%s isComplete=%s',
                    $acceptKey,
                    $uploaded,
                    $total,
                    $elapsedLabel,
                    $minSec,
                    $force ? '1' : '0',
                    $isComplete ? '1' : '0',
                ),
            );

            return;
        }
        Logger::logAgentInfo(
            $this->getId(),
            sprintf(
                'upload_progress: throttle SEND acceptKey=%s uploaded=%d/%d elapsedSinceLastSend=%s force=%s isComplete=%s',
                $acceptKey,
                $uploaded,
                $total,
                $elapsedLabel,
                $force ? '1' : '0',
                $isComplete ? '1' : '0',
            ),
        );
        Hilos::$rt->connections->actions->markUploadProgressThrottleTimestamp($acceptKey, $now);
        $this->sendFileUploadProgress($acceptKey, $progress);
    }

    /**
     * Lowercase basename for collision checks (null bytes stripped).
     */
    private function normalizeFilename(string $name): string
    {
        $base = basename(str_replace(["\0"], '', $name));

        return strtolower(trim($base));
    }

    /**
     * True if any active upload or published {@see ChatEventType::FILE_SHARED} uses the same normalized name.
     */
    private function isFilenameInUse(string $normalized): bool
    {
        foreach (Hilos::$rt->connections as $c) {
            if ($c->fileSessionUploadId === null) {
                continue;
            }
            if ((string)$c->fileSessionNormalizedFilename === $normalized) {
                return true;
            }
        }
        foreach (Hilos::$db->events as $event) {
            if ($event->type !== ChatEventType::FILE_SHARED->value) {
                continue;
            }
            $raw = $event->data;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $fn = $decoded['originalFilename'] ?? $decoded['filename'] ?? '';
            if (!is_string($fn)) {
                continue;
            }
            if ($this->normalizeFilename($fn) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort unlink of all regular files directly under a directory.
     */
    private function deleteFilesInDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }
}
