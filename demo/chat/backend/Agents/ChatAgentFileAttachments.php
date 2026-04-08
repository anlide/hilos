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
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Utils\ChatAttachmentStorage;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Logger;

/**
 * File upload (action + binary), quarantine, moderation, and UI state for ChatAgent.
 */
trait ChatAgentFileAttachments
{
    private const float FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC = 0.3;

    public function handleFileUploadInit(string $acceptKey, FileUploadInitActionDTO $dto): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }
        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        Hilos::$rt->userStates->actions->ensure($userId);

        $state = Hilos::$rt->userStates->getStateCollection()->get((string)$userId);
        if (!$state instanceof StateChatUserState) {
            return;
        }

        if ($state->hasActiveFileUploadSession()) {
            $this->abortFileUploadSession($userId, 'superseded_by_new_init');
            $this->sendToAllUserConnections(
                $userId,
                ChatSignalConstants::FILE_UPLOAD_ABORTED,
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

        $publishedTotal = $this->sumPublishedAttachmentBytes();
        $reserved = $this->sumActiveUploadDeclaredBytes();
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

        $sessionArr = [
            'uploadId' => $uploadId,
            'declaredSize' => $dto->size,
            'receivedBytes' => 0,
            'quarantineBasename' => $quarantineBasename,
            'originalFilename' => $dto->filename,
            'mimeType' => $dto->mimeType,
            'clientUploadId' => $dto->clientUploadId,
            'normalizedFilename' => $norm,
        ];

        Hilos::$rt->userStates->actions->applyDiffForUser(
            $userId,
            array_merge(
                StateChatUserState::diffForFileSession($sessionArr),
                [
                    StateChatUserState::fileProgressFilename => $dto->filename,
                    StateChatUserState::fileProgressUploadedBytes => 0,
                    StateChatUserState::fileProgressTotalBytes => $dto->size,
                    StateChatUserState::uploadProgressLastSentAt => 0.0,
                ],
            ),
        );

        Hilos::$rt->connections->actions->setFileUploadInitiatorForUser($userId, $acceptKey);

        Logger::logAgentInfo(
            $this->getId(),
            sprintf(
                'upload_progress: after_init userId=%d totalBytes=%d (force broadcast)',
                $userId,
                $dto->size,
            ),
        );
        $this->broadcastUploadProgressThrottled($userId, true);

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

    public function handleFileModerationDismiss(int $userId): void
    {
        Hilos::$rt->userStates->actions->ensure($userId);
        $state = Hilos::$rt->userStates->getStateCollection()->get((string)$userId);
        if (!$state instanceof StateChatUserState) {
            return;
        }
        $payload = $state->getFileModerationUiPayload();
        if ($payload === null || ($payload['phase'] ?? '') !== 'rejected') {
            return;
        }
        Hilos::$rt->userStates->actions->applyDiffForUser($userId, StateChatUserState::diffClearFileModerationUi());
        $this->broadcastFileModerationUi($userId);
    }

    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        if (!isset(Hilos::$rt->connections[$data->acceptKey])) {
            Logger::logAgentInfo($this->getId(), 'frame_binary: unknown acceptKey, ignoring');
            return;
        }
        $userId = Hilos::$rt->connections[$data->acceptKey]->userId;
        Hilos::$rt->userStates->actions->ensure($userId);
        $state = Hilos::$rt->userStates->getStateCollection()->get((string)$userId);
        if (!$state instanceof StateChatUserState) {
            return;
        }
        $session = $state->getFileUploadSessionArray();
        if ($session === null) {
            Logger::logAgentInfo($this->getId(), "frame_binary: no upload session userId={$userId}");
            $this->sendToAllUserConnections(
                $userId,
                ChatSignalConstants::FILE_UPLOAD_INVALID,
                new FileUploadInvalidSignalData('no_active_upload'),
            );
            return;
        }

        $chunk = $data->payload;
        $len = strlen($chunk);
        $declared = (int)$session['declaredSize'];
        $received = (int)$session['receivedBytes'];
        if ($received + $len > $declared) {
            Logger::logAgentError($this->getId(), "frame_binary: overflow userId={$userId}");
            $this->failFileUploadSession($userId, 'size_overflow');

            return;
        }

        $path = ChatAttachmentStorage::quarantinePathForBasename((string)$session['quarantineBasename']);
        if (file_put_contents($path, $chunk, FILE_APPEND) === false) {
            $this->failFileUploadSession($userId, 'write_error');
            return;
        }

        $newReceived = $received + $len;
        Hilos::$rt->userStates->actions->applyDiffForUser($userId, [
            StateChatUserState::fileSessionReceivedBytes => $newReceived,
            StateChatUserState::fileProgressUploadedBytes => $newReceived,
        ]);

        $state = Hilos::$rt->userStates->getStateCollection()->get((string)$userId);
        if (!$state instanceof StateChatUserState) {
            return;
        }
        $session = $state->getFileUploadSessionArray();
        if ($session === null) {
            return;
        }

        if ($state->getFileUploadProgressPayload() !== null) {
            $force = $newReceived === $declared;
            Logger::logAgentInfo(
                $this->getId(),
                sprintf(
                    'upload_progress: binary_chunk userId=%d chunkBytes=%d receivedAfter=%d declared=%d forceLastChunk=%s',
                    $userId,
                    $len,
                    $newReceived,
                    $declared,
                    $force ? '1' : '0',
                ),
            );
            $this->broadcastUploadProgressThrottled($userId, $force);
        } else {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: binary_chunk userId={$userId} skipped_no_progress_map (session exists)",
            );
        }

        if ($newReceived === $declared) {
            $this->completeFileUpload($userId, $data->acceptKey);
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    public function getFileModerationUiPayloadForUser(int $userId): ?array
    {
        $state = Hilos::$rt->userStates->getStateCollection()->get((string)$userId);
        if (!$state instanceof StateChatUserState) {
            return null;
        }

        return $state->getFileModerationUiPayload();
    }

    /**
     * @return ?array<string, mixed>
     */
    public function getFileUploadProgressPayloadForUser(int $userId): ?array
    {
        $state = Hilos::$rt->userStates->getStateCollection()->get((string)$userId);
        if (!$state instanceof StateChatUserState) {
            return null;
        }

        return $state->getFileUploadProgressPayload();
    }

    public function deleteAllAttachmentFilesFromDisk(): void
    {
        $published = ChatAttachmentStorage::publishedDir();
        $quarantine = ChatAttachmentStorage::quarantineDir();
        $this->deleteFilesInDir($published);
        $this->deleteFilesInDir($quarantine);
        Hilos::$rt->userStates->actions->clearAllFileRuntimeForAllUsers();

        $userIds = [];
        foreach (Hilos::$rt->connections as $conn) {
            $userIds[$conn->userId] = true;
        }
        foreach (array_keys($userIds) as $uid) {
            Hilos::$rt->connections->actions->setFileUploadInitiatorForUser((int)$uid, null);
        }

        foreach (array_keys($userIds) as $uid) {
            $this->sendToAllUserConnections(
                (int)$uid,
                ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                new FileModerationStateUpdateSignalData(null),
            );
            $this->sendToAllUserConnections(
                (int)$uid,
                ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
                new FileUploadProgressUpdateSignalData(null),
            );
        }
    }

    private function completeFileUpload(int $userId, string $acceptKey): void
    {
        $state = Hilos::$rt->userStates->getStateCollection()->get((string)$userId);
        if (!$state instanceof StateChatUserState) {
            return;
        }
        $session = $state->getFileUploadSessionArray();
        if ($session === null) {
            return;
        }

        Hilos::$rt->userStates->actions->applyDiffForUser(
            $userId,
            array_merge(
                StateChatUserState::diffClearFileSession(),
                StateChatUserState::diffClearFileProgress(),
            ),
        );
        Hilos::$rt->connections->actions->setFileUploadInitiatorForUser($userId, null);
        $this->sendFileUploadProgressToAllConnections($userId, null);

        Hilos::$rt->userStates->actions->applyDiffForUser($userId, [
            StateChatUserState::fileModPhase => 'moderating',
            StateChatUserState::fileModFilename => (string)$session['originalFilename'],
            StateChatUserState::fileModUploadedBytes => (int)$session['declaredSize'],
            StateChatUserState::fileModTotalBytes => (int)$session['declaredSize'],
            StateChatUserState::fileModReason => '',
            StateChatUserState::fileModUpdatedAt => time(),
        ]);
        $this->broadcastFileModerationUi($userId);

        $this->sendToAllUserConnections(
            $userId,
            ChatSignalConstants::FILE_UPLOAD_COMPLETE,
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
                userId: $userId,
                quarantineBasename: (string)$session['quarantineBasename'],
                originalFilename: (string)$session['originalFilename'],
                mimeType: (string)$session['mimeType'],
                size: (int)$session['declaredSize'],
                syntheticMessage: $synthetic,
            ),
        );
    }

    public function handleModerationFileResult(ModerationFileResultSignalData $result): void
    {
        $userId = $result->userId;
        Hilos::$rt->userStates->actions->ensure($userId);
        $path = ChatAttachmentStorage::quarantinePathForBasename($result->quarantineBasename);

        if (!$result->allow) {
            ChatAttachmentStorage::deleteIfExists($path);
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            Logger::logAgentError($this->getId(), "File blocked by moderation (userId={$userId}; reason={$reason})");
            Hilos::$rt->userStates->actions->applyDiffForUser($userId, [
                StateChatUserState::fileModPhase => 'rejected',
                StateChatUserState::fileModFilename => $result->originalFilename,
                StateChatUserState::fileModUploadedBytes => $result->size,
                StateChatUserState::fileModTotalBytes => $result->size,
                StateChatUserState::fileModReason => $reason,
                StateChatUserState::fileModUpdatedAt => time(),
            ]);
            $this->broadcastFileModerationUi($userId);

            return;
        }

        if (!is_file($path)) {
            Logger::logAgentError($this->getId(), "Moderation allow but quarantine file missing: {$result->quarantineBasename}");
            Hilos::$rt->userStates->actions->applyDiffForUser($userId, StateChatUserState::diffClearFileModerationUi());
            $this->broadcastFileModerationUi($userId);

            return;
        }

        $token = bin2hex(random_bytes(16));
        $ext = ChatAttachmentStorage::extensionForMime($result->mimeType);
        $storedName = $token . $ext;

        if (!ChatAttachmentStorage::moveToPublished($path, $storedName)) {
            Logger::logAgentError($this->getId(), 'Failed to move file to published storage');
            ChatAttachmentStorage::deleteIfExists($path);
            Hilos::$rt->userStates->actions->applyDiffForUser($userId, StateChatUserState::diffClearFileModerationUi());
            $this->broadcastFileModerationUi($userId);

            return;
        }

        Hilos::$rt->userStates->actions->applyDiffForUser($userId, StateChatUserState::diffClearFileModerationUi());
        $this->broadcastFileModerationUi($userId);

        $event = Hilos::$db->events->actions->add(ChatEventType::FILE_SHARED->value, $userId, null, [
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

    private function abortFileUploadSession(int $userId, string $reason): void
    {
        $state = Hilos::$rt->userStates->getStateCollection()->get((string)$userId);
        $hadProgress = $state instanceof StateChatUserState && $state->getFileUploadProgressPayload() !== null;
        if ($state instanceof StateChatUserState && $state->hasActiveFileUploadSession()) {
            $session = $state->getFileUploadSessionArray();
            if ($session !== null) {
                $path = ChatAttachmentStorage::quarantinePathForBasename((string)$session['quarantineBasename']);
                ChatAttachmentStorage::deleteIfExists($path);
            }
        }
        Hilos::$rt->userStates->actions->applyDiffForUser(
            $userId,
            array_merge(
                StateChatUserState::diffClearFileSession(),
                StateChatUserState::diffClearFileProgress(),
            ),
        );
        Hilos::$rt->connections->actions->setFileUploadInitiatorForUser($userId, null);
        if ($hadProgress) {
            $this->sendFileUploadProgressToAllConnections($userId, null);
        }
        Logger::logAgentInfo($this->getId(), "file upload aborted userId={$userId} reason={$reason}");
    }

    private function failFileUploadSession(int $userId, string $reason): void
    {
        $this->abortFileUploadSession($userId, $reason);
        $this->sendToAllUserConnections(
            $userId,
            ChatSignalConstants::FILE_UPLOAD_INVALID,
            new FileUploadInvalidSignalData($reason),
        );
    }

    private function sendToAllUserConnections(int $userId, string $signalName, SignalDataInterface $dto): void
    {
        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            $this->sendToUser($signalName, $connection->acceptKey, $dto);
        }
    }

    private function broadcastFileModerationUi(int $userId): void
    {
        $payload = $this->getFileModerationUiPayloadForUser($userId);
        $data = new FileModerationStateUpdateSignalData($payload);
        $this->sendToAllUserConnections($userId, ChatSignalConstants::FILE_MODERATION_STATE_UPDATE, $data);
    }

    private function sendFileUploadProgressToAllConnections(int $userId, ?array $payload): void
    {
        if ($payload !== null) {
            $up = (int)($payload['uploadedBytes'] ?? 0);
            $tot = (int)($payload['totalBytes'] ?? 0);
            Logger::logAgentInfo(
                $this->getId(),
                sprintf(
                    'upload_progress: ws_send FILE_UPLOAD_PROGRESS_UPDATE userId=%d uploaded=%d/%d',
                    $userId,
                    $up,
                    $tot,
                ),
            );
        } else {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: ws_send FILE_UPLOAD_PROGRESS_UPDATE userId={$userId} payload=null",
            );
        }
        $this->sendToAllUserConnections(
            $userId,
            ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
            new FileUploadProgressUpdateSignalData($payload),
        );
    }

    /**
     * @param bool $force When true, send immediately (subscribe, first progress after init, or final chunk).
     */
    private function broadcastUploadProgressThrottled(int $userId, bool $force): void
    {
        $state = Hilos::$rt->userStates->getStateCollection()->get((string)$userId);
        if (!$state instanceof StateChatUserState) {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: throttle userId={$userId} abort_no_state",
            );

            return;
        }
        $progress = $state->getFileUploadProgressPayload();
        if ($progress === null) {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: throttle userId={$userId} abort_no_progress_state",
            );

            return;
        }
        $uploaded = (int)($progress['uploadedBytes'] ?? 0);
        $total = (int)($progress['totalBytes'] ?? 0);
        $isComplete = $total > 0 && $uploaded >= $total;
        $now = microtime(true);
        $last = (float)$state->__get(StateChatUserState::uploadProgressLastSentAt);
        $elapsed = $last > 0.0 ? ($now - $last) : null;
        $elapsedLabel = $elapsed === null ? 'n/a_first_send' : sprintf('%.4fs', $elapsed);
        $minSec = self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC;
        if (!$force && !$isComplete && $elapsed !== null && $elapsed < $minSec) {
            Logger::logAgentInfo(
                $this->getId(),
                sprintf(
                    'upload_progress: throttle SKIP userId=%d uploaded=%d/%d elapsedSinceLastSend=%s need>=%.2fs force=%s isComplete=%s',
                    $userId,
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
                'upload_progress: throttle SEND userId=%d uploaded=%d/%d elapsedSinceLastSend=%s force=%s isComplete=%s',
                $userId,
                $uploaded,
                $total,
                $elapsedLabel,
                $force ? '1' : '0',
                $isComplete ? '1' : '0',
            ),
        );
        Hilos::$rt->userStates->actions->applyDiffForUser($userId, [
            StateChatUserState::uploadProgressLastSentAt => $now,
        ]);
        $this->sendFileUploadProgressToAllConnections($userId, $progress);
    }

    private function normalizeFilename(string $name): string
    {
        $base = basename(str_replace(["\0"], '', $name));

        return strtolower(trim($base));
    }

    private function isFilenameInUse(string $normalized): bool
    {
        foreach (Hilos::$rt->userStates->getStateCollection() as $st) {
            if (!$st instanceof StateChatUserState || !$st->hasActiveFileUploadSession()) {
                continue;
            }
            if ((string)$st->__get(StateChatUserState::fileSessionNormalizedFilename) === $normalized) {
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

    private function sumPublishedAttachmentBytes(): int
    {
        $sum = 0;
        foreach (Hilos::$db->events as $event) {
            if ($event->type !== ChatEventType::FILE_SHARED->value) {
                continue;
            }
            $raw = $event->data;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || !isset($decoded['size'])) {
                continue;
            }
            $sum += (int)$decoded['size'];
        }

        return $sum;
    }

    private function sumActiveUploadDeclaredBytes(): int
    {
        $sum = 0;
        foreach (Hilos::$rt->userStates->getStateCollection() as $st) {
            if (!$st instanceof StateChatUserState || !$st->hasActiveFileUploadSession()) {
                continue;
            }
            $sum += (int)$st->__get(StateChatUserState::fileSessionDeclaredSize)
                - (int)$st->__get(StateChatUserState::fileSessionReceivedBytes);
        }

        return $sum;
    }

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
