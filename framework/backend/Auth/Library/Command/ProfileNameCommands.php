<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Database\Actions\Item\UserActions;
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
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\ProfileNameRefusal;
use Hilos\Users\UserNotificationType;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use LogicException as NativeLogicException;
use Random\RandomException;

/** Checks a person's own name before asking its owner to write the name and journal together. */
final class ProfileNameCommands extends AbstractLibraryCommands
{
    private const float SWEEP_INTERVAL_SECONDS = 1.0;
    private const string STILL_CHECKING = 'Your new name is still being checked.';
    private const string NOTIFICATION_TITLE = 'Your new name was not accepted';
    private const string DATA_REASON = 'reason';
    private const string DATA_NEW_NAME = 'newName';

    private float $lastSweepAt = 0.0;

    /**
     * @param AbstractUsersLibraryAgent $library Library opening checks and sending owner asks
     * @param StepUpCommands $stepUp Operation confirmation gate
     */
    public function __construct(AbstractUsersLibraryAgent $library, private readonly StepUpCommands $stepUp)
    {
        parent::__construct($library);
    }

    /**
     * A name already carried opens no check and writes nothing.
     *
     * @param string $acceptKey Connection submitting its own name
     * @param string $name Requested display name
     * @throws ValidationException When confirmation, addressability or name validation refuses the submit
     * @throws HilosException When a row, check or frame fails
     * @throws NativeLogicException When the runtime factory rejects a check
     * @throws RandomException When the secure random source cannot mint a request id
     */
    public function set(string $acceptKey, string $name): void
    {
        $acting = $this->actingUser($acceptKey);
        AddressablePerson::require($acting->userId);
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_NAME);
        $name = trim($name);
        if ($name === '') {
            throw new EmptyValueException('User name cannot be empty');
        }
        if (mb_strlen($name) < UserActions::NAME_MIN_LENGTH) {
            throw new ValueTooShortException('User name is too short');
        }
        if (mb_strlen($name) > UserActions::NAME_MAX_LENGTH) {
            throw new ValueTooLongException('User name exceeds maximum length of ' . UserActions::NAME_MAX_LENGTH . ' characters');
        }
        if ($name === Hilos::$db->users[$acting->userId]->name) {
            return;
        }
        if (!Hilos::hasFeature(HilosFeature::MODERATION)) {
            $this->rename($acceptKey, $acting->userId, $name);

            return;
        }
        if (Hilos::$rt->hilosModerationChecks->find($acceptKey, ModerationKindKey::PROFILE_NAME) !== null) {
            throw new ValidationException(self::STILL_CHECKING);
        }

        Hilos::$rt->hilosModerationChecks->actions->open($acceptKey, $acting->userId, ModerationKindKey::PROFILE_NAME, text: $name);
        $this->tellChecking($acceptKey, true);
    }

    /**
     * A late or repeated verdict changes nothing. A frozen connection waits only until its deadline.
     *
     * @param ModerationVerdictSignalData $verdict Verdict of the current name attempt
     * @throws HilosException When state or a frame fails
     */
    public function verdict(ModerationVerdictSignalData $verdict): void
    {
        $check = Hilos::$rt->hilosModerationChecks->find($verdict->acceptKey, ModerationKindKey::PROFILE_NAME);
        if ($verdict->kind !== ModerationKindKey::PROFILE_NAME || $check === null || $check->requestId !== $verdict->requestId) {
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
     * Ends abandoned or expired name attempts at most once per second, leaving other kinds alone.
     *
     * @throws HilosException When state or a frame fails
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
            if ($check->kind !== ModerationKindKey::PROFILE_NAME) {
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
     * Snapshots the attempt before removing it; the browser stops waiting before the outcome arrives.
     *
     * @param HilosModerationCheck $check Name attempt to finish
     * @param bool $allow Whether moderation allowed it
     * @param string $reason Moderation reason code
     * @throws HilosException When state or a frame fails
     * @throws LogicException When a text check carries no text
     */
    private function finish(HilosModerationCheck $check, bool $allow, string $reason): void
    {
        $acceptKey = $check->acceptKey;
        $userId = $check->userId;
        $name = $check->text ?? throw new LogicException('A name check carries no text');
        $check->actions->forget();
        $this->tellChecking($acceptKey, false);
        if ($allow) {
            $this->rename($acceptKey, $userId, $name);

            return;
        }

        $message = ProfileNameRefusal::forReason($reason);
        if (ProfileNameRefusal::isAboutTheName($reason)) {
            $this->notifyRejected($userId, $name, $message);
        }
        $this->failSet($acceptKey, $message);
    }

    /**
     * @param HilosModerationCheck $check Attempt whose connection must still carry its person
     * @return ?bool True when live, false when gone or changed, null while its copy is stale
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
     * @param string $acceptKey Connection awaiting the outcome
     * @param int $userId Person who requested the name
     * @param string $name Validated name
     * @throws HilosException When addressability or a frame fails
     */
    private function rename(string $acceptKey, int $userId, string $name): void
    {
        try {
            AddressablePerson::require($userId);
        } catch (ValidationException $failure) {
            $this->failSet($acceptKey, AbstractUserAgent::renameRefusalWords($userId, $failure));

            return;
        }
        $this->library->sendToAgent(HilosSignalConstants::HILOS_USER_RENAME, new UserRenameSignalData(
            userId: $userId,
            name: $name,
            renamedByUserId: $userId,
            replySignal: HilosSignalConstants::HILOS_USER_RENAME_DONE,
            acceptKey: $acceptKey,
            requestId: null,
            action: HilosSignalConstants::PROFILE_NAME_SET,
            successMessage: null,
            answerSignal: null,
        ));
    }

    /**
     * @param string $acceptKey Connection to tell
     * @param bool $checking Whether it waits on a name check
     * @throws HilosException When the frame cannot be queued
     */
    private function tellChecking(string $acceptKey, bool $checking): void
    {
        $this->library->sendToUser(
            HilosSignalConstants::HILOS_MODERATION_CHECK,
            $acceptKey,
            new ModerationCheckSignalData(ModerationKindKey::PROFILE_NAME, $checking),
        );
    }

    /**
     * @param string $acceptKey Connection owed a refusal
     * @param string $message Safe refusal sentence
     * @throws HilosException When the frame cannot be queued
     */
    private function failSet(string $acceptKey, string $message): void
    {
        $this->library->sendToUser(
            SignalConstants::ACTION_ERROR,
            $acceptKey,
            new PageActionErrorSignalData(HilosSignalConstants::PROFILE_NAME_SET, $message),
        );
    }

    /**
     * @param int $userId Person to notify
     * @param string $name Requested name
     * @param string $message Safe refusal sentence
     */
    private function notifyRejected(int $userId, string $name, string $message): void
    {
        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $userId,
                type: UserNotificationType::NAME_REJECTED,
                title: self::NOTIFICATION_TITLE,
                severity: NotificationSeverity::WARNING,
                body: $message,
                data: [self::DATA_REASON => $message, self::DATA_NEW_NAME => $name],
            ));
        } catch (HilosException $failure) {
            Logger::logAgentWarning($this->library->getId(), "Name rejection notification failed: {$failure->getMessage()}");
        }
    }
}
