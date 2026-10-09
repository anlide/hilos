<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Code\DTO\CodeSendReplyDTO;
use Hilos\Auth\Exception\PasswordTooCommonException;
use Hilos\Auth\Exception\PasswordUnchangedException;
use Hilos\Auth\Library\DTO\ProfileAddPasswordConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfilePasswordUpdatedSignalData;
use Hilos\Auth\Library\DTO\ProfileSetPasswordActionDTO;
use Hilos\Auth\PasswordPolicy;
use Hilos\Auth\PhoneNumber;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Database\Verification\VerificationType;
use Hilos\Fs\FsException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Random\RandomException;

/**
 * The signed-in person's own ways in: adding them and taking them off (HIL-722, HIL-1137).
 *
 * The profile's half of the sign-in methods. Every command here acts on the account behind
 * the acting session and on nobody else: the person is resolved from the session, never
 * named by the client. Code requests answer with their send gate's outcome; the other writes
 * re-emit the identities projection, and a password, which nothing projects, is confirmed by
 * its own signal to every tab the person has open.
 *
 * The group began with the unlink alone, because unlinking a passkey is not one write: the
 * anchor row and the crypto sidecar are two tables, and only the sign-in commands are allowed
 * to know both. A project that called the identity primitive on its own - as the chat demo
 * did - took the anchor out and left a credential that still signed its owner in. The adds
 * came here for the same reason from the other side (HIL-1137): they were written in one
 * demo, and every other project declaring the sign-in feature had none of them.
 *
 * The order inside {@see unlink()} is the whole of what it promises. It is the one place a
 * passkey stops existing, so a project reaches it the way it reaches the register and login
 * ceremonies: through the library agent that owns it.
 *
 * Every add here is the operation 'add_sign_in_method' (HIL-1138): a browser left open must not
 * be enough for a stranger to give themselves a way in, so each step of an add takes the person
 * through {@see AbstractLibraryCommands::confirmedUser()} first, and a two-step add asks again on
 * its second step. Taking a way off is no operation, by the owner's decision: once every add is
 * confirmed, whatever is left to remove is the owner's own.
 *
 * The phone-code and email-code steps of an add live in the session's profile flow
 * (HIL-1184). Sending writes the destination there; confirmation reads it from that
 * record and ends the flow. The final submit carries the code, not the destination.
 */
final class IdentityCommands extends AbstractLibraryCommands
{
    /**
     * Adds the first password on the person's confirmed email (HIL-300).
     * Existing passwords can only change through PasswordChangeCommands, with operation and address proof.
     * An account with no verified email adds its first password through requestPasswordAdd().
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSetPasswordActionDTO $dto New password
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValueTooShortException When the password is shorter than the policy minimum
     * @throws PasswordTooCommonException When the password is in the common-password list
     * @throws FsException When the framework password list cannot be read
     * @throws PasswordUnchangedException When the password policy refuses a reused password
     * @throws ValidationException When the add is not confirmed, or the account already has a password or has no confirmed email
     * @throws InvalidArgumentException When the password-updated signal cannot be named or queued
     * @throws HilosException When an identity read or write fails
     */
    public function setPassword(string $acceptKey, ProfileSetPasswordActionDTO $dto): void
    {
        $userId = $this->confirmedUser($acceptKey, StepUpOperationKey::ADD_SIGN_IN_METHOD)->userId;
        if (Hilos::$db->identities->findPasswordByUser($userId) !== null) {
            throw new ValidationException(AuthMessages::ALREADY_HAS_PASSWORD);
        }
        $email = Hilos::$db->identities->findVerifiedEmailByUser($userId);
        if ($email === null) {
            throw new ValidationException(AuthMessages::CONFIRM_EMAIL_FIRST);
        }
        PasswordPolicy::assertValid($dto->newPassword, false);
        Hilos::$db->identities->createPasswordIdentity($userId, $email, $dto->newPassword)->markVerified();
        $this->library->announcePasswordUpdated($userId, ProfilePasswordUpdatedSignalData::MODE_ADDED);
    }

    /**
     * Step 1 of adding a phone: issues a code to the submitted number (HIL-403).
     *
     * The owning user is carried on the challenge so step 2 can assert the code was minted
     * for this person. The phone is normalized to E.164 (a malformed number is refused
     * synchronously); the send gate returns the cooldown and the earlier code's lifetime on a
     * held request, and refuses the per-window cap (HIL-421). No duplicate-phone check here:
     * a number is not tested for an owner until the code proves possession of it.
     * A live code puts the normalized number on the session's phone-code step; a held
     * send without a live code leaves the window on its number-entry step.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileAddSmsRequestActionDTO $dto Phone to send the code to
     * @return CodeSendReplyDTO Send outcome and server moments
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the add is not confirmed, or the phone is not a valid number
     * @throws EmptyValueException When the normalized identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When the verification query fails
     */
    public function requestSmsAdd(string $acceptKey, ProfileAddSmsRequestActionDTO $dto): CodeSendReplyDTO
    {
        $acting = $this->confirmedUser($acceptKey, StepUpOperationKey::ADD_SIGN_IN_METHOD);

        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null) {
            throw new ValidationException(AuthMessages::INVALID_PHONE);
        }

        $reply = $this->sendProfileCode($acting, StepUpOperationKey::ADD_SIGN_IN_METHOD, VerificationType::SMS_ADD, $phone);
        if ($reply->expiresAt === null) {
            return $reply;
        }

        $this->library->announceProfileFlowStep(
            $acting,
            StepUpOperationKey::ADD_SIGN_IN_METHOD,
            StateHilosProfileFlow::STEP_PHONE_SENT,
            $phone,
            $phone,
            $reply->expiresAt,
            $reply,
        );
        return $reply;
    }

    /**
     * Step 2 of adding a phone: verifies the code and attaches the number (HIL-403).
     *
     * The submitted code is verified against the `sms_add` challenge; a missing, expired or
     * wrong code - or a challenge minted for a different person than this session's -
     * is refused with the same generic message. The number comes from the session's
     * phone-code step; a missing or foreign step is refused before the code is spent. On
     * success a verified `sms` identity is attached to the person; the new row reaches every
     * connection through the identities projection re-emit. A phone already used by any
     * identity is refused and the existing link is never moved.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileAddSmsConfirmActionDTO $dto Code received by the phone in the session's flow
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the add is not confirmed, the flow or code is invalid, or the phone is already in use
     * @throws InvalidArgumentException When the completed-step frame cannot be queued
     * @throws HilosException When a verification or identity query fails
     */
    public function confirmSmsAdd(string $acceptKey, ProfileAddSmsConfirmActionDTO $dto): void
    {
        $acting = $this->confirmedUser($acceptKey, StepUpOperationKey::ADD_SIGN_IN_METHOD);
        $phone = $this->addingTo($acting, StateHilosProfileFlow::STEP_PHONE_SENT);
        if (new VerificationService()->verify(VerificationType::SMS_ADD, $phone, $dto->code) !== $acting->userId) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        try {
            Hilos::$db->identities->createSmsIdentity($acting->userId, $phone);
        } catch (DuplicateValueException) {
            throw new ValidationException(AuthMessages::PHONE_IN_USE);
        } catch (EmptyValueException) {
            throw new ValidationException(AuthMessages::INVALID_PHONE);
        }
        $this->library->announceProfileFlowStep($acting, StepUpOperationKey::ADD_SIGN_IN_METHOD, null);
    }

    /**
     * Step 1 of adding a password to an account with no verified email: mails a code (HIL-406).
     *
     * The owning user is carried on the challenge so step 2 can assert the code was minted
     * for this person. The email is lowercased and format-checked (a malformed address is
     * refused synchronously). Unlike the phone step, uniqueness IS checked here: the code is
     * mailed to the entered address, so an email already verified by ANOTHER account is
     * refused without sending anything - a stranger's verified address is never mailed. A
     * free email, or one already the person's own, is issued a code through the send gate,
     * which answers with the cooldown timing or refuses the cap (HIL-421).
     * A live code puts the normalized address on the session's email-code step;
     * a held send without a live code leaves the window on its address-entry step.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileAddPasswordRequestActionDTO $dto Address to send the code to
     * @return CodeSendReplyDTO Send outcome and server moments
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the add is not confirmed, or the email is malformed or already verified by another account
     * @throws EmptyValueException When the normalized identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When a verification or identity query fails
     */
    public function requestPasswordAdd(string $acceptKey, ProfileAddPasswordRequestActionDTO $dto): CodeSendReplyDTO
    {
        $acting = $this->confirmedUser($acceptKey, StepUpOperationKey::ADD_SIGN_IN_METHOD);
        $userId = $acting->userId;

        $email = strtolower($dto->email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(AuthMessages::INVALID_EMAIL);
        }

        $ownerId = Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($ownerId !== null && $ownerId !== $userId) {
            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        }

        $reply = $this->sendProfileCode($acting, StepUpOperationKey::ADD_SIGN_IN_METHOD, VerificationType::EMAIL_ADD, $email);
        if ($reply->expiresAt === null) {
            return $reply;
        }

        $this->library->announceProfileFlowStep(
            $acting,
            StepUpOperationKey::ADD_SIGN_IN_METHOD,
            StateHilosProfileFlow::STEP_EMAIL_SENT,
            $email,
            $email,
            $reply->expiresAt,
            $reply,
        );
        return $reply;
    }

    /**
     * Step 2 of adding a password: verifies the email code and writes the identity (HIL-406).
     *
     * The new password is checked FIRST so a weak password never burns the code, and an
     * account that already HAS a password is refused next, for the same reason and in its
     * own words (HIL-692): this flow adds a password, an account holds one, and the answer
     * does not depend on which address was typed - so spending the code to find that out
     * would burn it over a question already settled. The submitted code is then verified
     * against the `email_add` challenge; a missing, expired or wrong code - or a challenge
     * minted for a different person than this session's - is refused with the same generic
     * message. The address comes from the session's email-code step; a missing or foreign
     * step is refused before the code is spent. Uniqueness is re-checked after the code (a
     * magic-link-verified collision on the same email would slip past the password-scoped
     * duplicate guard of the write) before the write. On success a verified `password`
     * identity is attached on the now-proven email and the password-updated signal (added)
     * is fanned to all the person's connections; the new identity also arrives over the
     * projection re-emit.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileAddPasswordConfirmActionDTO $dto Code received by the email in the session's flow and new password
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValueTooShortException When the password is shorter than the policy minimum
     * @throws PasswordTooCommonException When the new password is in the common-password list
     * @throws FsException When the framework password list cannot be read
     * @throws ValidationException When the add is not confirmed, the account already has a password, the code is
     *     invalid or expired, or the email is already in use
     * @throws InvalidArgumentException When the password-updated signal cannot be named or queued
     * @throws HilosException When a verification or identity query fails
     */
    public function confirmPasswordAdd(string $acceptKey, ProfileAddPasswordConfirmActionDTO $dto): void
    {
        $acting = $this->confirmedUser($acceptKey, StepUpOperationKey::ADD_SIGN_IN_METHOD);

        // Nothing to be unchanged from: this flow only ever adds a password to an account
        // that has none, which is what the refusal below it enforces.
        PasswordPolicy::assertValid($dto->newPassword, false);

        if (Hilos::$db->identities->findPasswordByUser($acting->userId) !== null) {
            throw new ValidationException(AuthMessages::ALREADY_HAS_PASSWORD);
        }

        $email = $this->addingTo($acting, StateHilosProfileFlow::STEP_EMAIL_SENT);
        $verifiedUserId = new VerificationService()->verify(VerificationType::EMAIL_ADD, $email, $dto->code);
        if ($verifiedUserId !== $acting->userId) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        $ownerId = Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($ownerId !== null && $ownerId !== $acting->userId) {
            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        }

        try {
            Hilos::$db->identities->createPasswordIdentity($acting->userId, $email, $dto->newPassword)->markVerified();
        } catch (DuplicateValueException) {
            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        } catch (EmptyValueException) {
            throw new ValidationException(AuthMessages::INVALID_EMAIL);
        }

        $this->library->announcePasswordUpdated($acting->userId, ProfilePasswordUpdatedSignalData::MODE_ADDED);
        $this->library->announceProfileFlowStep($acting, StepUpOperationKey::ADD_SIGN_IN_METHOD, null);
    }

    /**
     * Reads the destination held by this person's phone-code or email-code step.
     *
     * @param ActingSession $acting Person and session confirming the add
     * @param string $step Expected STEP_* value
     * @return string Number or address receiving the code
     * @throws ValidationException When the session has no matching flow
     * @throws HilosException When the runtime collection cannot be read
     */
    private function addingTo(ActingSession $acting, string $step): string
    {
        $flow = Hilos::$rt->hilosProfileFlows[StateHilosProfileFlow::idFor(
            StateProtectedModeRuntime::hashSessionToken($acting->sessionToken),
            StepUpOperationKey::ADD_SIGN_IN_METHOD,
        )];
        if ($flow === null || $flow->userId !== $acting->userId || $flow->step !== $step || $flow->target === null) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        return $flow->target;
    }

    /**
     * Unlinks one of the acting user's sign-in methods, crypto half included.
     *
     * The profile's unlink door. The acting user is resolved here rather than passed in,
     * exactly as the ceremonies resolve theirs, so a project's handler is left with
     * nothing to get wrong about whose identity this is.
     *
     * Three steps, in this order and for this reason. The refusal to remove a last
     * sign-in method comes FIRST, before anything is written: a refusal has to leave
     * every row where it found it, and the primitive's own copy of the guard would fire
     * only after the credential was already gone. The credential goes SECOND and the
     * anchor THIRD, because an interruption between them has to leave the state that
     * closes the account rather than the one that opens it — an anchor without a
     * credential is a row the profile still lists and can be unlinked again, while a
     * credential without an anchor is a key that signs somebody in on a passkey they
     * were told they had removed.
     *
     * The primitive checks ownership and the last-method count again
     * ({@see Identities::deleteIdentity()}). That is not a duplicate to be cleaned up:
     * it is public, and a public write defends itself whoever calls it (HIL-377).
     * Ownership is why the cascade asks whose identity this is before it removes
     * anything: the primitive is what refuses a foreign identity, and it speaks after
     * the credential would already be gone — so an id belonging to somebody else is
     * left to it untouched, and the refusal costs that account nothing.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param int $identityId Identity id to unlink
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the identity is not the acting user's, or is their last one
     * @throws HilosException When an identity or credential lookup or delete fails
     */
    public function unlink(string $acceptKey, int $identityId): void
    {
        $acting = $this->actingUser($acceptKey);

        if (count(Hilos::$db->identities->listByUser($acting->userId)) <= 1) {
            throw new ValidationException('cannot remove your only sign-in method');
        }

        if (
            Hilos::$db->identities[$identityId]?->userId === $acting->userId
            && Hilos::$db->identities[$identityId]?->type === IdentityType::PASSKEY
        ) {
            Hilos::$db->passkeyCredentials->deleteByIdentity($identityId);
        }

        Hilos::$db->identities->deleteIdentity($acting->userId, $identityId);
    }
}
