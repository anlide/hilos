<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\DTO\StepUpOpeningReplyDTO;
use Hilos\Auth\StepUp\DTO\StepUpStartActionDTO;
use Hilos\Auth\StepUp\StepUpConfirmations;
use Hilos\Auth\StepUp\StepUpGate;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\StepUp\StepUpMethodResolver;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Random\RandomException;

/**
 * Opens, verifies and records fresh proof before a protected operation (HIL-495).
 *
 * The proof is asked of whoever the gate names as the confirmer ({@see StepUpGate::confirmer()}):
 * the person, or inside a takeover allowed to touch the sign-in the administrator behind it, whose
 * method, code, password or device key it then is, and on whom the confirmation is recorded
 * (HIL-1170). Nothing goes to the person whose account it is.
 */
final class StepUpCommands extends AbstractLibraryCommands
{
    /**
     * @param AbstractUsersLibraryAgent $library Users library executing the operation
     * @param SecondFactorCommands $secondFactor Second-factor proof commands
     * @param PasskeyCommands $passkeys Device-key proof commands
     */
    public function __construct(
        AbstractUsersLibraryAgent $library,
        private readonly SecondFactorCommands $secondFactor,
        private readonly PasskeyCommands $passkeys,
    ) {
        parent::__construct($library);
    }

    /**
     * Resolves whether a confirmation step is needed and starts its proof when necessary.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param StepUpStartActionDTO $dto Protected operation to open
     * @return StepUpOpeningReplyDTO Opening state for the operation modal
     * @throws ItemNotFoundForUpdateException When the acting connection holds neither a signed-in person nor an allowed block notice
     * @throws ValidationException When the operation is unknown, impersonated, has no proof, or reaches the send cap
     * @throws RandomException When a verification code or WebAuthn challenge cannot be drawn
     * @throws HilosException When settings, account proofs, verification delivery, or WebAuthn configuration fails
     */
    public function start(string $acceptKey, StepUpStartActionDTO $dto): StepUpOpeningReplyDTO
    {
        $acting = $this->actingPerson($acceptKey, $dto->operation);
        $directory = Hilos::stepUpOperationDirectoryClass();
        if (!$directory::has($dto->operation)) {
            throw new ValidationException(StepUpMessages::UNKNOWN_OPERATION);
        }

        $operation = $directory::get($dto->operation);
        $verdict = new StepUpGate()->verdict($acting->sessionToken, $acting->userId, $dto->operation);
        if ($verdict === StepUpGate::VERDICT_IMPERSONATED) {
            throw new ValidationException(StepUpMessages::IMPERSONATED);
        }
        if ($verdict === StepUpGate::VERDICT_PASS) {
            return new StepUpOpeningReplyDTO(false, $operation->purpose);
        }

        $acting = $this->confirming($acting, $dto->operation);
        $target = new StepUpMethodResolver()->resolve($acting->userId);
        if ($target === null) {
            throw new ValidationException(StepUpMessages::NOTHING_TO_CONFIRM_WITH);
        }

        $send = null;
        if ($target->method === StepUpMethod::EMAIL_CODE || $target->method === StepUpMethod::SMS_CODE) {
            $type = $target->method === StepUpMethod::EMAIL_CODE
                ? VerificationType::STEP_UP
                : VerificationType::STEP_UP_SMS;
            $send = $this->sendProfileCode($acting, HilosCodeSendAttempt::PURPOSE_STEP_UP, $type, (string)$target->destination);
        }

        $signedChallenge = null;
        $publicKeyOptions = null;
        if ($target->method === StepUpMethod::PASSKEY) {
            $passkey = $this->passkeys->stepUpOptions($acting);
            $signedChallenge = $passkey[StepUpOpeningReplyDTO::signedChallenge];
            $publicKeyOptions = $passkey[StepUpOpeningReplyDTO::publicKeyOptions];
        }

        return new StepUpOpeningReplyDTO(
            required: true,
            purpose: $operation->purpose,
            method: $target->method,
            destination: $target->destination,
            signedChallenge: $signedChallenge,
            publicKeyOptions: $publicKeyOptions,
            send: $send,
        );
    }

    /**
     * Verifies the selected proof and opens one operation in this browser for the TTL, and tells every
     * tab of the browser (HIL-1330).
     *
     * An operation this browser has already confirmed returns at once and tells nobody: the tabs
     * heard of it when it was written, and a tab that connected since was told on its handshake.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param StepUpConfirmActionDTO $dto Protected operation and proof returned by its opening
     * @throws ItemNotFoundForUpdateException When the acting connection holds neither a signed-in person nor an allowed block notice
     * @throws ValidationException When the operation, method, or proof is no longer valid
     * @throws HilosException When account proofs, verification, WebAuthn, env, or confirmation storage fails, or the frame cannot be queued
     */
    public function confirm(string $acceptKey, StepUpConfirmActionDTO $dto): void
    {
        $acting = $this->actingPerson($acceptKey, $dto->operation);
        $directory = Hilos::stepUpOperationDirectoryClass();
        if (!$directory::has($dto->operation)) {
            throw new ValidationException(StepUpMessages::UNKNOWN_OPERATION);
        }

        $verdict = new StepUpGate()->verdict($acting->sessionToken, $acting->userId, $dto->operation);
        if ($verdict === StepUpGate::VERDICT_IMPERSONATED) {
            throw new ValidationException(StepUpMessages::IMPERSONATED);
        }
        if ($verdict === StepUpGate::VERDICT_PASS) {
            return;
        }

        $acting = $this->confirming($acting, $dto->operation);
        $target = new StepUpMethodResolver()->resolve($acting->userId);
        if ($target === null || $target->method !== $dto->method) {
            throw new ValidationException(StepUpMessages::EXPIRED);
        }

        switch ($target->method) {
            case StepUpMethod::SECOND_FACTOR:
                $this->secondFactor->assertProof($acting->userId, $dto->code, $dto->backupCode);
                break;

            case StepUpMethod::PASSWORD:
                $password = Hilos::$db->identities->findPasswordByUser($acting->userId);
                if ($password === null || !$password->verifyPassword($dto->password)) {
                    throw new ValidationException(AuthMessages::WRONG_PASSWORD);
                }
                break;

            case StepUpMethod::EMAIL_CODE:
            case StepUpMethod::SMS_CODE:
                $type = $target->method === StepUpMethod::EMAIL_CODE
                    ? VerificationType::STEP_UP
                    : VerificationType::STEP_UP_SMS;
                if (new VerificationService()->verify($type, (string)$target->destination, $dto->code) === null) {
                    throw new ValidationException(AuthMessages::INVALID_CODE);
                }
                break;

            case StepUpMethod::PASSKEY:
                if ($dto->passkey === null) {
                    throw new ValidationException(StepUpMessages::PASSKEY_NOT_CONFIRMED);
                }
                $this->passkeys->assertStepUp($acting, $dto->passkey);
                break;

            default:
                throw new ValidationException(StepUpMessages::EXPIRED);
        }

        StepUpConfirmations::record($this->library, $acting->sessionToken, $acting->userId, $dto->operation);
    }

    /**
     * Applies the server-side gate at the start of a protected operation action.
     *
     * The project's door to the one prologue every framework command of a protected operation
     * takes ({@see AbstractLibraryCommands::confirmedUser()}, HIL-1138).
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $operation Protected operation key
     * @throws ItemNotFoundForUpdateException When the acting connection has no signed-in session
     * @throws ValidationException When impersonation is active or confirmation is absent or expired
     * @throws HilosException When the operation is not declared, or settings, account proofs, or confirmation storage cannot be read
     */
    public function require(string $acceptKey, string $operation): void
    {
        $this->confirmedUser($acceptKey, $operation);
    }

    /**
     * The browser and the person who prove the operation: the acting person, or the administrator behind a takeover.
     *
     * @param ActingSession $acting Browser and the person the session acts as
     * @param string $operation Declared operation key
     * @return ActingSession The same browser with the confirmer the gate names
     * @throws HilosException When the operation is not declared, or the session or the setting cannot be read
     */
    private function confirming(ActingSession $acting, string $operation): ActingSession
    {
        $confirmer = StepUpGate::confirmer($acting->sessionToken, (int)$acting->userId, $operation);

        return $confirmer === $acting->userId ? $acting : new ActingSession($acting->acceptKey, $acting->sessionToken, $confirmer);
    }
}
