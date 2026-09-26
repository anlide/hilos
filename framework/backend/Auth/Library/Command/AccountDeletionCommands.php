<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\AccountDeletionMessages;
use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Auth\AccountDeletion\AccountDeletionStateProjector;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionOpeningReplyDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionStartActionDTO;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\StepUp\StepUpGate;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\StepUp\StepUpMethodResolver;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpTarget;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * A person's own account deletion: opening the window, the code, the start and the cancel (HIL-302).
 *
 * The three steps that lead to a deletion open with the operation's confirmation
 * ({@see StepUpOperationKey::DELETE_ACCOUNT}, HIL-495) and then read the account again, so
 * nothing the window carried from an earlier step is trusted: whether a deletion already
 * stands, and where the code goes. The operation opens itself with a code to the account's
 * address, so a person the confirmation would have asked for a mailed code is not asked
 * twice - the gate passes them, and the code of the second step is the proof.
 *
 * Calling the deletion off is the opposite on purpose: no fresh proof, and not behind the
 * product's guard, because changing one's mind must be easier than deleting, and a frozen
 * account must still be able to do it. It is refused only under impersonation - what a
 * person does to their own account is never done with someone else's hands.
 *
 * Every start and cancel fans the person's new state to their group, so every open tab
 * turns the danger zone into the warning and back.
 */
final class AccountDeletionCommands extends AbstractLibraryCommands
{
    /**
     * @param AbstractUsersLibraryAgent $library Users library executing the deletion
     * @param StepUpCommands $stepUp Operation-level confirmation gate the three steps open with
     */
    public function __construct(
        AbstractUsersLibraryAgent $library,
        private readonly StepUpCommands $stepUp,
    ) {
        parent::__construct($library);
    }

    /**
     * Opens the window: the grace period, and where the code of the second step goes.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @return AccountDeletionOpeningReplyDTO Grace period, channel and address, or a null channel when no code can reach the account
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing or a deletion is already scheduled
     * @throws HilosException When a confirmation, identity, setting or request lookup fails
     */
    public function open(string $acceptKey): AccountDeletionOpeningReplyDTO
    {
        $userId = $this->actingUser($acceptKey)->userId;
        $this->stepUp->require($acceptKey, StepUpOperationKey::DELETE_ACCOUNT);
        $this->refuseScheduled($userId);
        $target = new StepUpMethodResolver()->resolveAddress($userId);

        return new AccountDeletionOpeningReplyDTO(
            AccountDeletionSettings::graceDays(),
            $target === null ? null : $this->channelOf($target),
            $target?->destination,
        );
    }

    /**
     * Sends the code that confirms the deletion to the account's address.
     *
     * The address is derived again; one that vanished since the window opened asks the person
     * to start over. The send gate's cooldown is a silent success - the code already waiting
     * is the one to type - while the window cap is refused out loud.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, a deletion is scheduled, no address is left, or the send cap is reached
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When a confirmation, identity, verification or request lookup fails
     */
    public function sendCode(string $acceptKey): void
    {
        $userId = $this->actingUser($acceptKey)->userId;
        $this->stepUp->require($acceptKey, StepUpOperationKey::DELETE_ACCOUNT);
        $this->refuseScheduled($userId);
        $target = new StepUpMethodResolver()->resolveAddress($userId);
        if ($target === null) {
            throw new ValidationException(StepUpMessages::EXPIRED);
        }

        $outcome = new VerificationService()->issue($this->codeTypeOf($target), (string)$target->destination, $userId);
        if ($outcome->capReached) {
            throw new ValidationException(AuthMessages::SEND_CAP);
        }
    }

    /**
     * Starts the deletion: checks and spends the code, then records the request.
     *
     * The moment of the erasure is fixed here - now plus the grace period in force - and never
     * moves. An account no code can reach starts without one. A wrong code spends an attempt,
     * as it does everywhere, and a wrong and an expired one are answered alike.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param AccountDeletionStartActionDTO $dto Code the account's address received
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the confirmation is missing, a deletion is scheduled, or the code does not match
     * @throws HilosException When a confirmation, identity, verification, setting or request write fails
     */
    public function start(string $acceptKey, AccountDeletionStartActionDTO $dto): void
    {
        $userId = $this->actingUser($acceptKey)->userId;
        $this->stepUp->require($acceptKey, StepUpOperationKey::DELETE_ACCOUNT);
        $this->refuseScheduled($userId);
        $target = new StepUpMethodResolver()->resolveAddress($userId);
        if (
            $target !== null
            && new VerificationService()->verify($this->codeTypeOf($target), (string)$target->destination, $dto->code) === null
        ) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        Hilos::$db->accountDeletions->actions->request(
            $userId,
            date('Y-m-d H:i:s', time() + AccountDeletionSettings::graceDays() * TimeConstants::SECONDS_PER_DAY),
        );
        $this->publishState($userId);
    }

    /**
     * Calls the scheduled deletion off.
     *
     * No confirmation and no product guard (see the class comment). Nothing scheduled - another
     * tab called it off first - is a silent success, and so is a cancel that lost the race to
     * the erasure: the state fanned after it tells every tab what is true.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the session is impersonated
     * @throws HilosException When the session, the request lookup or its update fails
     */
    public function cancel(string $acceptKey): void
    {
        $acting = $this->actingUser($acceptKey);
        if (StepUpGate::isImpersonated($acting->sessionToken)) {
            throw new ValidationException(StepUpMessages::IMPERSONATED);
        }

        $deletion = Hilos::$db->accountDeletions->liveOf($acting->userId);
        if ($deletion === null) {
            return;
        }

        $deletion->actions->cancel();
        $this->publishState($acting->userId);
    }

    /**
     * Fans the person's account deletion state to their group.
     *
     * @param int $userId Person
     * @throws HilosException When the state cannot be built or the signal queued
     */
    public function publishState(int $userId): void
    {
        $this->library->sendToGroup(
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_STATE,
            AccountDeletionGroup::forUser($userId),
            AccountDeletionStateProjector::stateFor($userId),
        );
    }

    /**
     * Refuses a step while a deletion is already scheduled - another tab started it meanwhile.
     *
     * @param int $userId Person
     * @throws ValidationException When a deletion stands
     * @throws HilosException When the request lookup fails
     */
    private function refuseScheduled(int $userId): void
    {
        if (Hilos::$db->accountDeletions->liveOf($userId) !== null) {
            throw new ValidationException(AccountDeletionMessages::ALREADY_SCHEDULED);
        }
    }

    /**
     * @param StepUpTarget $target Address a code goes to (EMAIL_CODE or SMS_CODE)
     * @return string The window's channel for that address
     */
    private function channelOf(StepUpTarget $target): string
    {
        return $target->method === StepUpMethod::EMAIL_CODE
            ? AccountDeletionOpeningReplyDTO::CHANNEL_EMAIL
            : AccountDeletionOpeningReplyDTO::CHANNEL_PHONE;
    }

    /**
     * @param StepUpTarget $target Address a code goes to (EMAIL_CODE or SMS_CODE)
     * @return string Verification type of the deletion code for that address
     */
    private function codeTypeOf(StepUpTarget $target): string
    {
        return $target->method === StepUpMethod::EMAIL_CODE
            ? VerificationType::ACCOUNT_DELETION
            : VerificationType::ACCOUNT_DELETION_SMS;
    }
}
