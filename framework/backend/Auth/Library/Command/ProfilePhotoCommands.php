<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Database\DatabaseException;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Moderation\DTO\ModerationCheckSignalData;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationReason;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\View\Item\HilosModerationCheck;
use Hilos\Users\AddressablePerson;
use Hilos\Users\DTO\UserPhotoPutDoneSignalData;
use Hilos\Users\DTO\UserPhotoPutSignalData;
use Hilos\Users\DTO\UserPhotoRemoveDoneSignalData;
use Hilos\Users\DTO\UserPhotoRemoveSignalData;
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use Hilos\Users\ProfilePhotoRefusal;
use Hilos\Users\UserNotificationType;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use LogicException as NativeLogicException;
use Random\RandomException;

/**
 * Sets, checks, publishes and removes a person's profile photo.
 *
 * With {@see HilosFeature::MODERATION} a photo is checked before it is published: this library
 * opens the check of the {@see ModerationKindKey::PROFILE_PHOTO} kind in the shared queue, tells
 * the connection it waits, applies the moderator's verdict and ends the wait the verdict never
 * reached. Without moderation a valid upload is published at once (HIL-1625).
 */
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
     * Submits one completed upload for publication, or for a moderation check first.
     *
     * Without moderation the upload goes to publication as it is, and the files library judges
     * it there. With moderation the upload is judged here before the check is opened - the
     * moderator reads it as it is now - and a connection waits on one photo check at a time.
     *
     * @param string $acceptKey Connection that submitted
     * @param string $clientUploadId Completed upload id
     * @throws ValidationException When photos are off, a check is pending or the upload is not ready
     * @throws HilosException When runtime state or signal delivery fails
     * @throws NativeLogicException When the runtime item factory rejects a check
     * @throws RandomException When the platform's secure random source refuses the request id
     */
    public function set(string $acceptKey, string $clientUploadId): void
    {
        $this->requireFeature();
        $acting = $this->actingUser($acceptKey);
        if (!Hilos::hasFeature(HilosFeature::MODERATION)) {
            $this->publish($acceptKey, $clientUploadId);

            return;
        }
        if (Hilos::$rt->hilosModerationChecks->find($acceptKey, ModerationKindKey::PROFILE_PHOTO) !== null) {
            throw new ValidationException(ProfilePhotoRefusal::STILL_CHECKING);
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

        Hilos::$rt->hilosModerationChecks->actions->open(
            $acceptKey,
            $acting->userId,
            ModerationKindKey::PROFILE_PHOTO,
            clientUploadId: $clientUploadId,
        );
        $this->tellChecking($acceptKey, true);
    }

    /**
     * Applies the moderator's verdict on a photo check to the attempt it answers.
     *
     * A verdict naming a check this connection no longer holds, or holds under another request
     * id, is late or repeated and changes nothing. A check whose connection closed or now carries
     * somebody else ends without a word to anybody. A check past its deadline ends unavailable
     * whatever the verdict says, as the sweep would end it. While the connection's copy is frozen
     * nothing is decided on the verdict, and the deadline ends the wait. Otherwise the check ends
     * first, the connection is told it no longer waits, and the photo is published or refused.
     *
     * @param ModerationVerdictSignalData $verdict Verdict of a {@see ModerationKindKey::PROFILE_PHOTO} check
     * @throws HilosException When state, a publication frame or a refusal frame fails
     */
    public function verdict(ModerationVerdictSignalData $verdict): void
    {
        $check = Hilos::$rt->hilosModerationChecks->find($verdict->acceptKey, ModerationKindKey::PROFILE_PHOTO);
        if ($verdict->kind !== ModerationKindKey::PROFILE_PHOTO || $check === null || $check->requestId !== $verdict->requestId) {
            Logger::logAgentWarning(
                $this->library->getId(),
                "Moderation verdict has no matching photo check: {$verdict->acceptKey}/{$verdict->kind}/{$verdict->requestId}",
            );

            return;
        }
        $carried = $this->carriedBy($check);
        if ($carried === false) {
            $check->actions->forget();

            return;
        }
        if (TimeHelper::nowMs() >= $check->deadlineAt) {
            $this->finish($check, false, ModerationReason::SERVICE_UNAVAILABLE);

            return;
        }
        if ($carried === null) {
            return;
        }
        $this->finish($check, $verdict->allow, $verdict->reason);
    }

    /**
     * Asks the person's agent to write the photo row once a file is published (HIL-1409).
     *
     * The checks on the publication stay here. A person who is gone is told on the tab that
     * submitted, and the agent is not raised. The row itself, the first one included, is the
     * agent's. The browser action was answered when the upload was sent, so this is not a
     * hand-off the action waits on.
     *
     * @param FilesPublishedSignalData $answer Files library's publication result
     * @throws HilosException When the file cannot be read or a frame cannot be queued
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type, or the ask cannot be named
     * @throws DatabaseException When the person or the merges cannot be loaded
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
        if (!isset(Hilos::$db->files[$fileId]) || Hilos::$db->files[$fileId]->ownerUserId === null) {
            $this->failSet($answer->acceptKey, self::PUBLISH_FAILED);

            return;
        }

        $file = Hilos::$db->files[$fileId];
        try {
            AddressablePerson::require($file->ownerUserId);
        } catch (ValidationException $e) {
            $this->failSet($answer->acceptKey, $e->getMessage());

            return;
        }

        $this->library->sendToAgent(
            HilosSignalConstants::HILOS_USER_PHOTO_PUT,
            new UserPhotoPutSignalData(
                userId: $file->ownerUserId,
                fileId: $fileId,
                replySignal: HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE,
                acceptKey: $answer->acceptKey,
                requestId: null,
                action: HilosSignalConstants::PROFILE_PHOTO_SET,
                successMessage: null,
            ),
        );
    }

    /**
     * Binds the new file and releases the former one after the person's agent wrote the row (HIL-1409).
     *
     * A refused write tells the tab and leaves the unbound file for the files janitor. The former
     * file is released only when the agent named one other than the file just set.
     *
     * @param UserPhotoPutDoneSignalData $done The agent's answer, carrying the ask back
     * @throws HilosException When the failure frame or the restate frame cannot be queued
     * @throws LogicException When the files door is missing
     */
    public function afterPut(UserPhotoPutDoneSignalData $done): void
    {
        $ask = $done->ask;
        if ($done->error !== null) {
            $this->failSet($ask->acceptKey, $done->error);

            return;
        }

        try {
            $this->files()->markBound([$ask->fileId]);
        } catch (HilosException $failure) {
            Logger::logAgentWarning($this->library->getId(), "Photo file bind announcement failed: {$failure->getMessage()}");
        }
        if ($done->formerFileId !== null && $done->formerFileId !== $ask->fileId) {
            try {
                $this->files()->remove([$done->formerFileId]);
            } catch (HilosException $failure) {
                Logger::logAgentWarning($this->library->getId(), "Former photo file removal failed: {$failure->getMessage()}");
            }
        }
        $this->restate($ask->userId);
    }

    /**
     * Asks the person's agent to remove the photo row (HIL-1409).
     *
     * The feature, the acting person and a pending photo check of this connection are judged
     * here: the photo check ends without a verdict, and a check of another kind is left alone.
     * The row is the agent's, and a person with no photo is answered once the agent says so.
     *
     * @param string $acceptKey Connection that submitted
     * @throws ValidationException When photos are not enabled, or the person was erased or merged
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws HilosException When state or a frame cannot be queued
     * @throws LogicException When no action is being dispatched, or a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type, or the ask cannot be named
     * @throws DatabaseException When the person or the merges cannot be loaded
     */
    public function remove(string $acceptKey): void
    {
        $this->requireFeature();
        $acting = $this->actingUser($acceptKey);
        $check = Hilos::hasFeature(HilosFeature::MODERATION)
            ? Hilos::$rt->hilosModerationChecks->find($acceptKey, ModerationKindKey::PROFILE_PHOTO)
            : null;
        if ($check !== null) {
            $check->actions->forget();
            $this->tellChecking($acceptKey, false);
        }

        $this->library->askPersonAgent(
            $acting->userId,
            HilosSignalConstants::HILOS_USER_PHOTO_REMOVE,
            new UserPhotoRemoveSignalData(
                userId: $acting->userId,
                replySignal: HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->library->currentActionRequestId(),
                action: $this->library->runningAction(),
                successMessage: null,
            ),
        );
    }

    /**
     * Releases the file the person's agent removed, and refreshes open sessions (HIL-1409).
     *
     * No file means the person had no photo: nothing is released and no session is refreshed.
     * The action is answered with nothing either way.
     *
     * @param UserPhotoRemoveDoneSignalData $done The agent's answer, carrying the ask back
     * @return ?ActionReplyDTO Nothing: the removal has no reply of its own
     * @throws HilosException When the restate frame cannot be queued
     * @throws LogicException When the files door is missing
     */
    public function afterRemove(UserPhotoRemoveDoneSignalData $done): ?ActionReplyDTO
    {
        if ($done->fileId === null) {
            return null;
        }

        try {
            $this->files()->remove([$done->fileId]);
        } catch (HilosException $failure) {
            Logger::logAgentWarning($this->library->getId(), "Photo file removal failed: {$failure->getMessage()}");
        }
        $this->restate($done->ask->userId);

        return null;
    }

    /**
     * Ends the photo checks the moderator's verdict will not settle, no more than once per second.
     *
     * A check whose connection closed, or now carries somebody else, is removed without a word to
     * anybody. A check past its deadline ends unavailable: the moderator does not start it, and
     * one that stopped would otherwise leave the connection waiting forever - a connection whose
     * copy here is frozen included, since only the deadline is sure to end its wait.
     *
     * @throws HilosException When runtime state cannot be read or changed, or a frame cannot be queued
     */
    public function sweepChecks(): void
    {
        $now = microtime(true);
        if ($now - $this->lastSweepAt < self::SWEEP_INTERVAL_SECONDS) {
            return;
        }
        $this->lastSweepAt = $now;
        if (!Hilos::hasFeature(HilosFeature::MODERATION)) {
            return;
        }

        $nowMs = TimeHelper::nowMs();
        foreach (Hilos::$rt->hilosModerationChecks as $check) {
            if ($check->kind !== ModerationKindKey::PROFILE_PHOTO) {
                continue;
            }
            if ($this->carriedBy($check) === false) {
                $check->actions->forget();
            } elseif ($nowMs >= $check->deadlineAt) {
                $this->finish($check, false, ModerationReason::SERVICE_UNAVAILABLE);
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
     * Ends a photo check and settles what it was asked for.
     *
     * The row leaves first, so a second submission is accepted from the moment the connection
     * hears it no longer waits. A refusal about the photo itself notifies the person as well; a
     * check that could not be had only answers the tab.
     *
     * @param HilosModerationCheck $check Photo check to end
     * @param bool $allow Whether the photo passed
     * @param string $reason Reason code of the verdict
     * @throws HilosException When state, a publication frame or a refusal frame fails
     * @throws LogicException When the check names no upload, which an image kind's opening refuses
     */
    private function finish(HilosModerationCheck $check, bool $allow, string $reason): void
    {
        $acceptKey = $check->acceptKey;
        $clientUploadId = $check->clientUploadId ?? throw new LogicException('A photo check carries no upload');
        $userId = $check->userId;
        $check->actions->forget();
        $this->tellChecking($acceptKey, false);

        if ($allow) {
            $this->publish($acceptKey, $clientUploadId);

            return;
        }

        $message = ProfilePhotoRefusal::forReason($reason);
        if (ProfilePhotoRefusal::isAboutThePhoto($reason)) {
            $this->notifyRejected($userId, $reason, $message);
        }
        $this->failSet($acceptKey, $message);
    }

    /**
     * Whether the connection of a check still carries the person who asked.
     *
     * A project with no connections mounted has nobody to compare, and its checks stand.
     *
     * @param HilosModerationCheck $check Check to judge
     * @return ?bool True while it does, false once the connection closed or carries somebody else,
     *     null while this process holds a frozen copy of the connection
     */
    private function carriedBy(HilosModerationCheck $check): ?bool
    {
        $connections = Hilos::$rt->connectionsSource();
        if ($connections === null) {
            return true;
        }
        $connection = $connections->get($check->acceptKey);
        if ($connection === null) {
            return false;
        }
        $collection = $connections->getCollectionName();
        if ($collection !== null && RtStaleness::staleSince($collection, $check->acceptKey) !== null) {
            return null;
        }

        return $connection->userId === $check->userId;
    }

    /**
     * @param string $acceptKey Connection to tell
     * @param bool $checking Whether it now waits on a photo check
     * @throws HilosException When the frame cannot be queued
     */
    private function tellChecking(string $acceptKey, bool $checking): void
    {
        $this->library->sendToUser(
            HilosSignalConstants::HILOS_MODERATION_CHECK,
            $acceptKey,
            new ModerationCheckSignalData(ModerationKindKey::PROFILE_PHOTO, $checking),
        );
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
     * @param string $reason Reason code of the refusal
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
}
