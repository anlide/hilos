<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\ProfileNameBeginSignalData;
use Hilos\Auth\Library\DTO\ProfileNameContinueSignalData;
use Hilos\Auth\Library\DTO\ProfileNameAdvanceSignalData;
use Hilos\Auth\StepUp\StepUpGate;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\View\Item\HilosProfileNameChange;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Actions\Item\UserActions;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\View\Item\Session;
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
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use Hilos\Users\ProfileNameRefusal;
use Hilos\Users\UserNotificationType;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use LogicException as NativeLogicException;
use Random\RandomException;
use Throwable;

/** Checks a person's own name before asking its owner to write the name and journal together. */
final class ProfileNameCommands extends AbstractLibraryCommands
{
    private const float SWEEP_INTERVAL_SECONDS = 1.0;
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
     * Validates the submitting socket once, then hands admission to its session holder.
     *
     * @param string $acceptKey Connection submitting its own name
     * @param string $name Requested display name
     * @throws ValidationException When confirmation, addressability or name validation refuses the submit
     * @throws HilosException When a row, check or frame fails
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
        $this->library->sendToAgent(HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN, new ProfileNameBeginSignalData(
            $acting->sessionToken, $acting->userId, $name, $acceptKey,
        ));
    }

    /**
     * A continuation is a wake-up, not evidence that its preceding RT write has landed here.
     * The source callbacks execute each accepted phase; this frame rechecks its admission guards.
     *
     * @param ProfileNameContinueSignalData $frame Accepted session attempt
     * @throws HilosException When the attempt, check or waiting frame cannot be read or queued
     */
    public function continueChange(ProfileNameContinueSignalData $frame): void
    {
        if (!Hilos::hasFeature(HilosFeature::AUTH)) {
            return;
        }
        $change = Hilos::$rt->hilosProfileNameChanges[$frame->sessionTokenHash];
        if ($change === null || $change->staleSince() !== null || $change->requestId !== $frame->requestId
            || $change->phase !== StateHilosProfileNameChange::PHASE_CHECKING
            || !Hilos::hasFeature(HilosFeature::MODERATION)
        ) {
            return;
        }
        try {
            $this->requireCurrentPerson($change);
        } catch (ValidationException $failure) {
            $this->advance($change, StateHilosProfileNameChange::PHASE_REFUSED,
                AbstractUserAgent::renameRefusalWords($change->userId, $failure));
        }
    }

    /**
     * Runs a phase from its RT source event, including when CONTINUE arrived before that event.
     * Applying is handed off only on the holder's phase change, never on duplicate wake-ups.
     *
     * @param string $hash Session token hash
     * @param string $requestId Attempt observed in the current source row
     * @param string $phase Phase announced by the source
     * @throws HilosException When a session, check, guard or frame fails
     */
    public function resumeChange(string $hash, string $requestId, string $phase): void
    {
        if (!Hilos::hasFeature(HilosFeature::AUTH)) {
            return;
        }
        $change = Hilos::$rt->hilosProfileNameChanges[$hash];
        if ($change === null || $change->staleSince() !== null || $change->requestId !== $requestId || $change->phase !== $phase) {
            return;
        }
        if (!in_array($phase, [StateHilosProfileNameChange::PHASE_CHECKING, StateHilosProfileNameChange::PHASE_APPLYING], true)) {
            return;
        }
        try {
            $session = $this->requireCurrentPerson($change);
        } catch (ValidationException $failure) {
            $this->advance($change, StateHilosProfileNameChange::PHASE_REFUSED,
                AbstractUserAgent::renameRefusalWords($change->userId, $failure));

            return;
        }
        if ($phase === StateHilosProfileNameChange::PHASE_APPLYING) {
            JournalReceiptScope::run(
                JournalReceiptData::web($change->userId, null, $session->id, HilosSignalConstants::PROFILE_NAME_SET, $this->library->getId()),
                fn () => $this->library->sendToAgent(HilosSignalConstants::HILOS_USER_RENAME, new UserRenameSignalData(
                    userId: $change->userId,
                    name: $change->name,
                    renamedByUserId: $change->userId,
                    replySignal: HilosSignalConstants::HILOS_USER_RENAME_DONE,
                    acceptKey: $change->originAcceptKey,
                    requestId: $change->requestId,
                    action: HilosSignalConstants::PROFILE_NAME_SET,
                    successMessage: null,
                    answerSignal: null,
                )),
            );

            return;
        }
        if (!Hilos::hasFeature(HilosFeature::MODERATION)) {
            return;
        }
        if ($change->deadlineAt === null || $change->deadlineAt <= TimeHelper::nowMs()) {
            $this->advance($change, StateHilosProfileNameChange::PHASE_REFUSED,
                ProfileNameRefusal::forReason(ModerationReason::SERVICE_UNAVAILABLE));

            return;
        }
        $check = Hilos::$rt->hilosModerationChecks->find($change->originAcceptKey, ModerationKindKey::PROFILE_NAME);
        if ($check !== null) {
            if ($check->requestId === $change->requestId || $check->staleSince() !== null) {
                return;
            }
            $check->actions->forget();
        }
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(
                $change->originAcceptKey, $change->userId, ModerationKindKey::PROFILE_NAME, text: $change->name,
                sessionTokenHash: $hash, requestId: $requestId, deadlineAt: $change->deadlineAt,
            );
        } catch (NativeLogicException|RandomException $failure) {
            throw new LogicException('The accepted name check could not be opened.', previous: $failure);
        }
        $this->tellChecking($change, true);
    }

    /**
     * A late, mismatched or frozen verdict cannot advance the accepted attempt.
     *
     * @param ModerationVerdictSignalData $verdict Verdict of the current name attempt
     * @throws HilosException When state or a frame fails
     */
    public function verdict(ModerationVerdictSignalData $verdict): void
    {
        if (!Hilos::hasFeature(HilosFeature::MODERATION) || $verdict->kind !== ModerationKindKey::PROFILE_NAME) {
            return;
        }
        $check = Hilos::$rt->hilosModerationChecks->find($verdict->acceptKey, ModerationKindKey::PROFILE_NAME);
        if ($check === null || $check->staleSince() !== null || $check->requestId !== $verdict->requestId) {
            return;
        }
        $carried = $this->carriedBy($check);
        if ($carried === null) {
            return;
        }
        if (!$carried) {
            $check->actions->forget();

            return;
        }
        if (TimeHelper::nowMs() >= $check->deadlineAt) {
            $this->finish($check, false, ModerationReason::SERVICE_UNAVAILABLE);

            return;
        }
        $this->finish($check, $verdict->allow, $verdict->reason);
    }

    /**
     * Reclaims orphan checks; an unreachable source is unknown, not absent.
     *
     * @throws HilosException When state or a frame fails
     */
    public function sweepChecks(): void
    {
        $now = microtime(true);
        if ($now - $this->lastSweepAt < self::SWEEP_INTERVAL_SECONDS || !Hilos::hasFeature(HilosFeature::MODERATION)) {
            return;
        }
        $this->lastSweepAt = $now;
        foreach (Hilos::$rt->hilosModerationChecks as $check) {
            if ($check->kind !== ModerationKindKey::PROFILE_NAME || $check->staleSince() !== null) {
                continue;
            }
            $carried = $this->carriedBy($check);
            if ($carried === false) {
                $check->actions->forget();
            } elseif ($carried === true && TimeHelper::nowMs() >= $check->deadlineAt) {
                $this->finish($check, false, ModerationReason::SERVICE_UNAVAILABLE);
            }
        }
    }

    /**
     * Resolves an own-name owner reply by operation identity, without relying on its dead socket.
     * The same gate protects the completion hook and its final report from late duplicates.
     *
     * @param UserRenameSignalData $ask Existing owner ask returned unchanged
     * @return ?HilosProfileNameChange Matching applying attempt, or null for an obsolete reply
     * @throws HilosException When the accepted attempts cannot be read
     */
    public function acceptedRename(UserRenameSignalData $ask): ?HilosProfileNameChange
    {
        if (!Hilos::hasFeature(HilosFeature::AUTH) || $ask->action !== HilosSignalConstants::PROFILE_NAME_SET
            || $ask->requestId === null || $ask->renamedByUserId !== $ask->userId
        ) {
            return null;
        }
        foreach (Hilos::$rt->hilosProfileNameChanges as $change) {
            if ($change->staleSince() === null && $change->requestId === $ask->requestId
                && $change->userId === $ask->userId && $change->name === $ask->name
                && $change->originAcceptKey === $ask->acceptKey
                && $change->phase === StateHilosProfileNameChange::PHASE_APPLYING
            ) {
                return $change;
            }
        }

        return null;
    }

    /**
     * Restates the person's sessions on a current successful completion. Announcement failure
     * leaves that completion intact; a later handshake carries the stored name.
     *
     * @param UserRenameDoneSignalData $done Existing owner reply after journal and hooks
     * @throws HilosException When the current attempt or phase report cannot be read or queued
     */
    public function renamed(UserRenameDoneSignalData $done): void
    {
        $change = $this->acceptedRename($done->ask);
        if ($change !== null) {
            if ($done->error === null) {
                try {
                    $this->library->sendToAgent(
                        HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE,
                        new UserSessionsRestateSignalData($change->userId),
                    );
                } catch (Throwable $failure) {
                    Logger::logAgentError($this->library->getId(), "Name restate for #{$change->userId} failed: {$failure->getMessage()}");
                }
            }
            $this->advance($change, $done->error === null
                ? StateHilosProfileNameChange::PHASE_DONE : StateHilosProfileNameChange::PHASE_REFUSED, $done->error);
        }
    }

    /**
     * @param HilosModerationCheck $check Current check whose accepted attempt was validated
     * @param bool $allow Model permission
     * @param string $reason Moderation reason
     * @throws HilosException When the check, phase report or compatibility frame fails
     * @throws LogicException When a text check carries no text
     */
    private function finish(HilosModerationCheck $check, bool $allow, string $reason): void
    {
        $name = $check->text ?? throw new LogicException('A name check carries no text');
        $change = $check->sessionTokenHash === null ? null : Hilos::$rt->hilosProfileNameChanges[$check->sessionTokenHash];
        $check->actions->forget();
        if ($change === null) {
            return;
        }
        $this->tellChecking($change, false);
        try {
            $this->requireCurrentPerson($change);
        } catch (ValidationException $failure) {
            $this->advance($change, StateHilosProfileNameChange::PHASE_REFUSED,
                AbstractUserAgent::renameRefusalWords($change->userId, $failure));

            return;
        }
        if ($allow && $reason === ModerationReason::OK) {
            $this->advance($change, StateHilosProfileNameChange::PHASE_APPLYING);

            return;
        }
        $reason = $allow ? ModerationReason::SERVICE_UNAVAILABLE : $reason;
        $message = ProfileNameRefusal::forReason($reason);
        if (ProfileNameRefusal::isAboutTheName($reason)) {
            $this->notifyRejected($check->userId, $name, $message);
        }
        $this->advance($change, StateHilosProfileNameChange::PHASE_REFUSED, $message);
    }

    /**
     * @param HilosModerationCheck $check Attempt whose current source must still carry its person
     * @return ?bool True when current, false when orphaned, null while the source is stale
     * @throws HilosException When the attempt cannot be read
     */
    private function carriedBy(HilosModerationCheck $check): ?bool
    {
        if ($check->sessionTokenHash !== null && Hilos::hasFeature(HilosFeature::AUTH)) {
            if (RtStaleness::staleSince(StateHilosProfileNameChange::RT_COLLECTION, $check->sessionTokenHash) !== null) {
                return null;
            }
            $change = Hilos::$rt->hilosProfileNameChanges[$check->sessionTokenHash];

            return $change !== null && $change->requestId === $check->requestId && $change->userId === $check->userId
                && $change->originAcceptKey === $check->acceptKey && $change->name === $check->text
                && $change->phase === StateHilosProfileNameChange::PHASE_CHECKING && $change->deadlineAt === $check->deadlineAt;
        }
        $connections = Hilos::$rt->connectionsSource();
        if ($connections === null) {
            return true;
        }
        $collection = $connections->getCollectionName();
        if ($collection !== null && RtStaleness::staleSince($collection, $check->acceptKey) !== null) {
            return null;
        }

        return $connections->get($check->acceptKey)?->userId === $check->userId;
    }

    /**
     * Re-checks the same identity, standing, addressability and confirmation without a socket.
     *
     * @param HilosProfileNameChange $change Accepted operation
     * @return Session Session that still authorizes this accepted operation
     * @throws HilosException When the current person, session or confirmation refuses the write
     */
    private function requireCurrentPerson(HilosProfileNameChange $change): Session
    {
        AddressablePerson::require($change->userId);
        $standing = AccountStandingResolver::of($change->userId);
        if ($standing->blocked || $standing->frozen) {
            throw new ValidationException(ProfileNameRefusal::forReason(ModerationReason::SERVICE_UNAVAILABLE));
        }
        foreach (Hilos::$db->sessions->findByUserId($change->userId) as $session) {
            if (ProtectedModeRuntime::hashSessionToken($session->token) !== $change->sessionTokenHash) {
                continue;
            }
            if ($session->expiresAt !== null && $session->expiresAt <= TimeHelper::getSqlDateTime()) {
                break;
            }
            new StepUpGate()->require($session->token, $change->userId, StepUpOperationKey::CHANGE_NAME);

            return $session;
        }

        throw new ValidationException(ProfileNameRefusal::forReason(ModerationReason::SERVICE_UNAVAILABLE));
    }

    /**
     * @param HilosProfileNameChange $change Current attempt
     * @param string $phase Reported phase
     * @param ?string $refusal Safe refusal sentence
     * @throws HilosException When the phase report cannot be queued
     */
    private function advance(HilosProfileNameChange $change, string $phase, ?string $refusal = null): void
    {
        $this->library->sendToAgent(HilosSignalConstants::HILOS_PROFILE_NAME_ADVANCE, new ProfileNameAdvanceSignalData(
            $change->sessionTokenHash, $change->requestId, $phase, $refusal,
        ));
    }

    /**
     * @param HilosProfileNameChange $change Session operation whose original socket is still listening
     * @param bool $checking Whether it waits on moderation
     * @throws HilosException When the compatibility frame cannot be queued
     */
    private function tellChecking(HilosProfileNameChange $change, bool $checking): void
    {
        $connections = Hilos::$rt->sessionConnectionsSource();
        $connection = $connections?->get($change->originAcceptKey);
        $collection = $connections?->getCollectionName();
        if ($connection?->sessionToken === null || $connection->userId !== $change->userId
            || ProtectedModeRuntime::hashSessionToken($connection->sessionToken) !== $change->sessionTokenHash
            || ($collection !== null && RtStaleness::staleSince($collection, $change->originAcceptKey) !== null)
        ) {
            return;
        }
        $this->library->sendToUser(
            HilosSignalConstants::HILOS_MODERATION_CHECK,
            $change->originAcceptKey,
            new ModerationCheckSignalData(ModerationKindKey::PROFILE_NAME, $checking),
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
