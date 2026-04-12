<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Main;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Router\DTO\FileModerationStateUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadAbortedSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadReadySignalData;
use Demo\Chat\Core\Router\DTO\FileUploadRejectedSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Utils\ChatSettingsHelper;
use Hilos\Fs\FsException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Utils\Logger;
use Hilos\Utils\Helpers\FileSystemHelper;
use Random\RandomException;

/**
 * Main-page file upload init and file-moderation dismiss actions; uses {@see ChatAgent} for user-targeted signals.
 */
trait UploadFileTrait
{
    abstract protected function getChatAgent(): ChatAgent;

    /**
     * Handle {@see ChatSignalConstants::FILE_UPLOAD_INIT}: validate limits and filename, create tmp file,
     * start session, send {@see ChatSignalConstants::FILE_UPLOAD_READY}. Replaces an in-flight upload on the same socket.
     *
     * @param string $acceptKey WebSocket connection id
     * @throws RandomException If {@see random_bytes()} fails
     * @throws RtActionsCollectionNameNullException When the connections actions collection name is null
     * @throws RtTruthSourceWriteNotAllowedException When the truth source rejects a runtime write
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
            Logger::logAgentInfo(
                $agent->getId(),
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
            Logger::logAgentError($agent->getId(), "Cannot create tmp file: {$e->getMessage()}");
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
     * @throws RtActionsCollectionNameNullException
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
