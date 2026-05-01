<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Main;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Router\DTO\AttachmentDraftsUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadAbortedSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadCompleteSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadInvalidSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadProgressUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadReadySignalData;
use Demo\Chat\Core\Router\DTO\FileUploadRejectedSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Collection\AttachmentDrafts;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\HilosException;
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
     * Minimum wall-clock interval between {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE} sends when not forced.
     */
    private const float FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC = 0.3;

    /**
     * Handle {@see ChatSignalConstants::FILE_UPLOAD_INIT}: validate limits and filename, create tmp file,
     * start session, send {@see ChatSignalConstants::FILE_UPLOAD_READY}. Replaces an in-flight upload on the same socket.
     *
     * @param string $acceptKey WebSocket connection id
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

        $this->deleteExpiredAttachmentDrafts();

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

        $publishedTotal = Hilos::$db->eventAttachments->sumPublishedAttachmentBytes();
        $reserved = Hilos::$rt->connections->sumActiveUploadReservedBytes();
        $draftTotal = Hilos::$rt->attachmentDrafts->sumDraftBytes();
        if ($publishedTotal + $reserved + $draftTotal + $dto->size > $maxTotal) {
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
     * Used from admin/cron cleanup so all tabs drop draft and progress UI.
     */
    protected function deleteAllAttachmentFilesFromDisk(): void
    {
        $agent = $this->getChatAgent();

        Hilos::$fs->published->deleteAll();
        Hilos::$fs->quarantine->deleteAll();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$rt->connections->actions->clearAllFileRuntimeOnAllConnections();

        foreach (Hilos::$rt->connections as $connection) {
            $acceptKey = $connection->acceptKey;
            $agent->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
                $acceptKey,
                new FileUploadProgressUpdateSignalData(null),
            );
            $this->sendAttachmentDraftsUpdate($acceptKey);
        }
    }

    /**
     * After the last binary chunk: move tmp to quarantine, clear upload session and progress UI,
     * create an attachment draft, and send {@see ChatSignalConstants::FILE_UPLOAD_COMPLETE}.
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
        $normalizedFilename = $connection->fileSessionNormalizedFilename;

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

        $draft = Hilos::$rt->attachmentDrafts->actions->create(
            draftId: $uploadId,
            acceptKey: $acceptKey,
            userId: $connection->userId,
            quarantineBasename: $storedName,
            originalFilename: $originalFilename,
            mimeType: $mimeType,
            size: $declaredSize,
            normalizedFilename: $normalizedFilename,
            uploadedAt: time(),
        );
        $draftPayload = AttachmentDrafts::toFrontendRow($draft);

        $agent->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_COMPLETE,
            $acceptKey,
            new FileUploadCompleteSignalData(
                uploadId: $uploadId,
                filename: $originalFilename,
                attachmentDraft: $draftPayload,
            ),
        );
        $this->sendAttachmentDraftsUpdate($acceptKey);
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
     * uses for 0 / total). Reads progress from the connection runtime fields.
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
     * Delete expired drafts and notify live connections whose draft list changed.
     */
    protected function deleteExpiredAttachmentDrafts(): void
    {
        foreach (Hilos::$rt->attachmentDrafts->actions->deleteExpired(time()) as $acceptKey) {
            if (isset(Hilos::$rt->connections[$acceptKey])) {
                $this->sendAttachmentDraftsUpdate($acceptKey);
            }
        }
    }

    /**
     * Send the full draft list for a connection.
     */
    protected function sendAttachmentDraftsUpdate(string $acceptKey): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::ATTACHMENT_DRAFTS_UPDATE,
            $acceptKey,
            new AttachmentDraftsUpdateSignalData(
                Hilos::$rt->attachmentDrafts->toFrontendListForAcceptKey($acceptKey),
            ),
        );
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
