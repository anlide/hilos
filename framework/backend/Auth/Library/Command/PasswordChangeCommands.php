<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\ProfileChangePasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordOpeningReplyDTO;
use Hilos\Auth\Library\DTO\ProfilePasswordUpdatedSignalData;
use Hilos\Auth\PasswordPolicy;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\StepUp\StepUpMethodResolver;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpTarget;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\View\Item\Identity;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * Changes an existing password after operation confirmation and address proof (HIL-300).
 * Every action resolves the person and address again. The code remains live until the final
 * submit accepts the password; reset codes are then invalidated for the person on every address.
 */
final class PasswordChangeCommands extends AbstractLibraryCommands
{
    /**
     * @param AbstractUsersLibraryAgent $library Users library executing the change
     * @param StepUpCommands $stepUp Confirmation gate shared with the library's other operations
     */
    public function __construct(
        AbstractUsersLibraryAgent $library,
        private readonly StepUpCommands $stepUp,
    ) {
        parent::__construct($library);
    }

    /**
     * @param string $acceptKey Accept key the action arrived on
     * @return ProfileChangePasswordOpeningReplyDTO Current code destination, or a null channel when none is reachable
     * @throws ValidationException When confirmation is missing or the account has no password
     * @throws HilosException When the session, confirmation or identity lookup fails
     */
    public function open(string $acceptKey): ProfileChangePasswordOpeningReplyDTO
    {
        $acting = $this->actingUser($acceptKey);
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_PASSWORD);
        $this->requirePassword($acting->userId);
        $target = new StepUpMethodResolver()->resolveAddress($acting->userId);

        return new ProfileChangePasswordOpeningReplyDTO(
            $target === null ? null : $this->channelOf($target),
            $target?->destination,
        );
    }

    /**
     * The resend cooldown is a silent success; the send cap refuses the request.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @throws ValidationException When confirmation, password or address is missing, or the send cap is reached
     * @throws RandomException When the platform cannot produce a verification code
     * @throws HilosException When the session, confirmation, identity or verification operation fails
     */
    public function requestCode(string $acceptKey): void
    {
        $acting = $this->actingUser($acceptKey);
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_PASSWORD);
        $this->requirePassword($acting->userId);
        $target = new StepUpMethodResolver()->resolveAddress($acting->userId);
        if ($target === null) {
            throw new ValidationException(StepUpMessages::EXPIRED);
        }

        if (new VerificationService()->issue($this->codeTypeOf($target), (string)$target->destination, $acting->userId)->capReached) {
            throw new ValidationException(AuthMessages::SEND_CAP);
        }
    }

    /**
     * Checks without spending: the final submit carries the same code. A wrong code costs an attempt.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileChangePasswordCodeConfirmActionDTO $dto Code the account address received
     * @throws ValidationException When confirmation, password or address is missing, or the code does not match
     * @throws HilosException When the session, confirmation, identity or verification operation fails
     */
    public function confirmCode(string $acceptKey, ProfileChangePasswordCodeConfirmActionDTO $dto): void
    {
        $acting = $this->actingUser($acceptKey);
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_PASSWORD);
        $this->requirePassword($acting->userId);
        $target = new StepUpMethodResolver()->resolveAddress($acting->userId);
        if ($target === null) {
            throw new ValidationException(StepUpMessages::EXPIRED);
        }

        if (!new VerificationService()->matchCode($this->codeTypeOf($target), (string)$target->destination, $dto->code)) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }
    }

    /**
     * Proof precedes password policy: "already your password" must not answer an unproven guess
     * (HIL-654). A weak password spends no code, and only the winner of the atomic spend writes.
     * Reset challenges are deleted by person, because any account address can have received one.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileChangePasswordActionDTO $dto Proof, new password and the person's session choice
     * @throws ValidationException When confirmation, password or proof is missing, or password policy refuses the secret
     * @throws HilosException When a session, confirmation, identity, verification, password-list or announcement operation fails
     */
    public function change(string $acceptKey, ProfileChangePasswordActionDTO $dto): void
    {
        $acting = $this->actingUser($acceptKey);
        $this->stepUp->require($acceptKey, StepUpOperationKey::CHANGE_PASSWORD);
        $password = $this->requirePassword($acting->userId);
        $target = new StepUpMethodResolver()->resolveAddress($acting->userId);
        $verifications = new VerificationService();
        if (
            $target !== null
            && (
                !$verifications->hasActive($this->codeTypeOf($target), (string)$target->destination)
                || !$verifications->matchCode($this->codeTypeOf($target), (string)$target->destination, $dto->code)
            )
        ) {
            throw new ValidationException(StepUpMessages::EXPIRED);
        }

        PasswordPolicy::assertValid($dto->newPassword, $password->verifyPassword($dto->newPassword));
        if ($target !== null && !$verifications->consumeActive($this->codeTypeOf($target), (string)$target->destination)) {
            throw new ValidationException(StepUpMessages::EXPIRED);
        }

        $password->setPassword($dto->newPassword);
        Hilos::$db->verifications->deleteForUserOfType($acting->userId, VerificationType::PASSWORD_RESET);
        $this->library->announcePasswordUpdated($acting->userId, ProfilePasswordUpdatedSignalData::MODE_CHANGED);
        if ($dto->signOutOthers) {
            $this->library->announceOtherSessionsEnd($acting);
        }
    }

    /**
     * @param int $userId Person whose existing password changes
     * @return Identity Existing password identity
     * @throws ValidationException When the account no longer has a password
     * @throws HilosException When the identity lookup fails
     */
    private function requirePassword(int $userId): Identity
    {
        return Hilos::$db->identities->findPasswordByUser($userId)
            ?? throw new ValidationException(AuthMessages::NO_PASSWORD);
    }

    /**
     * @param StepUpTarget $target Address a code can reach
     * @return string Opening reply channel
     */
    private function channelOf(StepUpTarget $target): string
    {
        return $target->method === StepUpMethod::EMAIL_CODE
            ? ProfileChangePasswordOpeningReplyDTO::CHANNEL_EMAIL
            : ProfileChangePasswordOpeningReplyDTO::CHANNEL_PHONE;
    }

    /**
     * @param StepUpTarget $target Address a code can reach
     * @return string Password-change verification type for the channel
     */
    private function codeTypeOf(StepUpTarget $target): string
    {
        return $target->method === StepUpMethod::EMAIL_CODE
            ? VerificationType::PASSWORD_CHANGE
            : VerificationType::PASSWORD_CHANGE_SMS;
    }
}
