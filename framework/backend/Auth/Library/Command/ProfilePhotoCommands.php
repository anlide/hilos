<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Database\Database;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\DTO\ProfilePhotoCheckSignalData;
use Hilos\Users\DTO\ProfilePhotoVerdictSignalData;
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use Hilos\Users\ProfilePhotoRefusal;
use Hilos\Users\UserNotificationType;
use Hilos\Utils\Logger;

/** Sets, checks, publishes and removes a person's profile photo. */
final class ProfilePhotoCommands extends AbstractLibraryCommands
{
    private const float SWEEP_INTERVAL_SECONDS = 1.0;

    private const string UPLOAD_GONE = 'This file is gone; upload it again';
    private const string UPLOAD_NOT_FINISHED = 'This file has not finished uploading';
    private const string UPLOAD_OTHER_TARGET = 'This file was uploaded for something else';
    private const string PUBLISH_FAILED = 'Cannot keep the file';
    private const string DATA_REASON = 'reason';
    private const string DATA_URL = 'url';

    private float $lastSweepAt = 0.0;

    /**
     * Submits one completed upload for publication or a project check.
     *
     * @param string $acceptKey Connection that submitted
     * @param string $clientUploadId Completed upload id
     * @throws ValidationException When photos are off, a check is pending or the upload is not ready
     * @throws HilosException When runtime state or signal delivery fails
     */
    public function set(string $acceptKey, string $clientUploadId): void
    {
        $this->requireFeature();
        $acting = $this->actingUser($acceptKey);
        if (Hilos::$rt->hilosProfilePhotoChecks[$acceptKey] !== null) {
            throw new ValidationException(ProfilePhotoRefusal::STILL_CHECKING);
        }

        if (Hilos::appClass()::PROFILE_PHOTO_CHECKER === null) {
            $this->publish($acceptKey, $clientUploadId);

            return;
        }

        $upload = Hilos::$rt->hilosUploads->find($acceptKey, $clientUploadId);
        if ($upload === null || $upload->userId !== $acting->userId) {
            throw new ValidationException(self::UPLOAD_GONE);
        }
        if ($upload->target !== ProfilePhotoUploadTarget::NAME) {
            throw new ValidationException(self::UPLOAD_OTHER_TARGET);
        }
        if ($upload->phase !== UploadPhase::COMPLETE) {
            throw new ValidationException(self::UPLOAD_NOT_FINISHED);
        }

        Hilos::$rt->hilosProfilePhotoChecks->actions->open($acceptKey, $acting->userId, $clientUploadId);
        $this->library->sendToUser(
            HilosSignalConstants::HILOS_PROFILE_PHOTO_CHECK,
            $acceptKey,
            new ProfilePhotoCheckSignalData(true),
        );
    }

    /**
     * Applies a project's verdict to the still-pending upload.
     *
     * @param ProfilePhotoVerdictSignalData $verdict Verdict and upload identity
     * @throws HilosException When state, a publication frame or a refusal frame fails
     */
    public function verdict(ProfilePhotoVerdictSignalData $verdict): void
    {
        $check = Hilos::$rt->hilosProfilePhotoChecks[$verdict->acceptKey];
        if ($check === null || $check->clientUploadId !== $verdict->clientUploadId) {
            Logger::logAgentWarning(
                $this->library->getId(),
                "Profile photo verdict has no matching check: {$verdict->acceptKey}/{$verdict->clientUploadId}",
            );

            return;
        }

        $check->actions->forget();
        $this->library->sendToUser(
            HilosSignalConstants::HILOS_PROFILE_PHOTO_CHECK,
            $verdict->acceptKey,
            new ProfilePhotoCheckSignalData(false),
        );

        if ($verdict->allow) {
            $this->publish($verdict->acceptKey, $verdict->clientUploadId);

            return;
        }

        $message = ProfilePhotoRefusal::forReason($verdict->reason);
        if (ProfilePhotoRefusal::isAboutThePhoto($verdict->reason)) {
            $this->notifyRejected($check->userId, $verdict->reason, $message);
        }
        $this->failSet($verdict->acceptKey, $message);
    }

    /**
     * Binds a published registry file to the person's photo row and releases the former file.
     *
     * @param FilesPublishedSignalData $answer Files library's publication result
     * @throws HilosException When a database write or signal delivery fails
     */
    public function published(FilesPublishedSignalData $answer): void
    {
        if ($answer->error !== null) {
            $this->failSet($answer->acceptKey, $answer->error);

            return;
        }
        if (count($answer->fileIds) !== 1) {
            $this->failSet($answer->acceptKey, self::PUBLISH_FAILED);

            return;
        }

        $fileId = $answer->fileIds[0];
        $file = Hilos::$db->files[$fileId];
        if ($file?->ownerUserId === null) {
            $this->failSet($answer->acceptKey, self::PUBLISH_FAILED);

            return;
        }

        Database::transactionStart();
        try {
            $oldFileId = Hilos::$db->userPhotos->actions->put($file->ownerUserId, $fileId);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            $this->rollBack();

            throw $failure;
        }

        try {
            $this->files()->markBound([$fileId]);
        } catch (HilosException $failure) {
            Logger::logAgentWarning($this->library->getId(), "Photo file bind announcement failed: {$failure->getMessage()}");
        }
        if ($oldFileId !== null && $oldFileId !== $fileId) {
            try {
                $this->files()->remove([$oldFileId]);
            } catch (HilosException $failure) {
                Logger::logAgentWarning($this->library->getId(), "Former photo file removal failed: {$failure->getMessage()}");
            }
        }
        $this->restate($file->ownerUserId);
    }

    /**
     * Removes the acting person's photo and its registry file.
     *
     * @param string $acceptKey Connection that submitted
     * @throws ValidationException When photos are not enabled
     * @throws HilosException When state, persistence or signal delivery fails
     */
    public function remove(string $acceptKey): void
    {
        $this->requireFeature();
        $acting = $this->actingUser($acceptKey);
        $check = Hilos::$rt->hilosProfilePhotoChecks[$acceptKey];
        if ($check !== null) {
            $check->actions->forget();
            $this->library->sendToUser(
                HilosSignalConstants::HILOS_PROFILE_PHOTO_CHECK,
                $acceptKey,
                new ProfilePhotoCheckSignalData(false),
            );
        }
        if (Hilos::$db->userPhotos[$acting->userId] === null) {
            return;
        }

        Database::transactionStart();
        try {
            $fileId = Hilos::$db->userPhotos->actions->deleteForUser($acting->userId);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            $this->rollBack();

            throw $failure;
        }

        if ($fileId !== null) {
            try {
                $this->files()->remove([$fileId]);
            } catch (HilosException $failure) {
                Logger::logAgentWarning($this->library->getId(), "Photo file removal failed: {$failure->getMessage()}");
            }
        }
        $this->restate($acting->userId);
    }

    /**
     * Sweeps checks whose connections have gone, no more than once per second.
     *
     * @throws HilosException When runtime state cannot be read or changed
     */
    public function sweepClosedConnections(): void
    {
        $now = microtime(true);
        if ($now - $this->lastSweepAt < self::SWEEP_INTERVAL_SECONDS) {
            return;
        }
        $this->lastSweepAt = $now;

        $connections = Hilos::$rt->connectionsSource();
        if ($connections === null) {
            return;
        }
        foreach (Hilos::$rt->hilosProfilePhotoChecks as $check) {
            if ($connections->get($check->acceptKey) === null) {
                $check->actions->forget();
            }
        }
    }

    /**
     * @throws ValidationException When profile photos are not enabled
     */
    private function requireFeature(): void
    {
        if (!Hilos::hasFeature(HilosFeature::PROFILE_PHOTO)) {
            throw new ValidationException(ProfilePhotoRefusal::PHOTOS_NOT_KEPT);
        }
    }

    /**
     * @param string $acceptKey Connection that submitted
     * @param string $clientUploadId Complete upload id
     * @throws HilosException When the file door or signal delivery fails
     */
    private function publish(string $acceptKey, string $clientUploadId): void
    {
        $this->files()->publishUploads(
            $acceptKey,
            ProfilePhotoUploadTarget::NAME,
            [$clientUploadId],
            HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED,
        );
    }

    /**
     * @return HilosFiles Files registry door
     * @throws LogicException When the required files feature has no door
     */
    private function files(): HilosFiles
    {
        return Hilos::$files ?? throw new LogicException('The files door is not created');
    }

    /**
     * @param string $acceptKey Connection owed the failure
     * @param string $message Caller-facing refusal
     * @throws HilosException When the failure frame cannot be queued
     */
    private function failSet(string $acceptKey, string $message): void
    {
        $this->library->sendToUser(
            SignalConstants::ACTION_ERROR,
            $acceptKey,
            new PageActionErrorSignalData(HilosSignalConstants::PROFILE_PHOTO_SET, $message),
        );
    }

    /**
     * @param int $userId Person to tell
     * @param string $reason Project checker reason
     * @param string $message Caller-facing refusal
     */
    private function notifyRejected(int $userId, string $reason, string $message): void
    {
        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $userId,
                type: UserNotificationType::PHOTO_REJECTED,
                title: ProfilePhotoRefusal::NOTIFICATION_TITLE,
                severity: NotificationSeverity::WARNING,
                body: $message,
                data: [self::DATA_REASON => $reason, self::DATA_URL => ProfilePhotoRefusal::PROFILE_PATH],
            ));
        } catch (HilosException $failure) {
            Logger::logAgentWarning($this->library->getId(), "Photo rejection notification failed: {$failure->getMessage()}");
        }
    }

    /**
     * @param int $userId Person whose open sessions need a fresh identity
     * @throws HilosException When the restate frame cannot be queued
     */
    private function restate(int $userId): void
    {
        $this->library->sendToAgent(
            HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE,
            new UserSessionsRestateSignalData($userId),
        );
    }

    /** Keeps a failed transaction from swallowing the failure that caused it. */
    private function rollBack(): void
    {
        try {
            Database::transactionRollback();
        } catch (HilosException) {
            // The original failure is the one the caller is owed.
        }
    }
}
