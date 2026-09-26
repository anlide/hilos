<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Exception\PasswordUnchangedException;
use Hilos\Auth\Library\DTO\ProfileAddPasswordConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfilePasswordUpdatedSignalData;
use Hilos\Auth\Library\DTO\ProfileSetPasswordActionDTO;
use Hilos\Auth\PasswordPolicy;
use Hilos\Auth\PhoneNumber;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * The signed-in person's own ways in: adding them and taking them off (HIL-722, HIL-1137).
 *
 * The profile's half of the sign-in methods. Every command here acts on the account behind
 * the acting session and on nobody else: the person is resolved from the session, never
 * named by the client, and each command answers with nothing - the identities projection
 * re-emits what changed, and a password, which nothing projects, is confirmed by its own
 * signal to every tab the person has open.
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
 */
final class IdentityCommands extends AbstractLibraryCommands
{
    /**
     * Adds or changes the signed-in person's password from the profile (HIL-402).
     *
     * Two in-scope flows, chosen from the account's own identities (never from a client
     * flag): a CHANGE (the account already has a `password` identity) re-auths the current
     * password before rewriting the secret; an ADD (no password yet) attaches a `password`
     * identity to the account's already-proven email - no email code, since the email is
     * verified - and marks it verified. An account with no verified email (SMS-only or
     * legacy OAuth) is refused here; its way to a password is the email-and-code pair
     * ({@see requestPasswordAdd()}, HIL-406). On success the password-updated signal is
     * fanned to all the person's connections, because a change moves nothing in the
     * identity projection that could confirm it.
     *
     * Which password it changes is the framework's answer (HIL-692): an account holds one,
     * and asking for it by account is what makes "your password is changed" true even for
     * data written before the rule.
     *
     * The password gate is asked LAST on the change branch, after the current password has
     * been checked, and the order is the security property (HIL-654). It can answer "that is
     * already your password", so a form that asked it first would hand the account's
     * password to anybody who guessed it in the NEW field, without ever having to type the
     * current one. The cost of the right order is a smaller one: a submit with both a wrong
     * current password and a short new one is told about the current one.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileSetPasswordActionDTO $dto New password and, for a change, the current one
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValueTooShortException When the new password is shorter than the policy minimum
     * @throws PasswordUnchangedException When the new password is the one the account already has
     * @throws ValidationException When the current password is wrong or the account has no verified email
     * @throws InvalidArgumentException When the password-updated signal cannot be named or queued
     * @throws HilosException When an identity read or secret write query fails
     */
    public function setPassword(string $acceptKey, ProfileSetPasswordActionDTO $dto): void
    {
        $userId = $this->actingUser($acceptKey)->userId;

        $passwordIdentity = Hilos::$db->identities->findPasswordByUser($userId);
        if ($passwordIdentity !== null) {
            if ($dto->currentPassword === '' || !$passwordIdentity->verifyPassword($dto->currentPassword)) {
                throw new ValidationException(AuthMessages::CURRENT_PASSWORD_INCORRECT);
            }
            PasswordPolicy::assertValid($dto->newPassword, $passwordIdentity->verifyPassword($dto->newPassword));
            $passwordIdentity->setPassword($dto->newPassword);
            $mode = ProfilePasswordUpdatedSignalData::MODE_CHANGED;
        } else {
            $email = Hilos::$db->identities->findVerifiedEmailByUser($userId);
            if ($email === null) {
                throw new ValidationException(AuthMessages::CONFIRM_EMAIL_FIRST);
            }
            PasswordPolicy::assertValid($dto->newPassword, false);
            Hilos::$db->identities->createPasswordIdentity($userId, $email, $dto->newPassword)->markVerified();
            $mode = ProfilePasswordUpdatedSignalData::MODE_ADDED;
        }

        $this->fanPasswordUpdated($userId, $mode);
    }

    /**
     * Step 1 of adding a phone: issues a code to the submitted number (HIL-403).
     *
     * The owning user is carried on the challenge so step 2 can assert the code was minted
     * for this person. The phone is normalized to E.164 (a malformed number is refused
     * synchronously); the code is issued through the send gate, which can drop the request
     * silently - the resend cooldown for a repeat pressed too soon, and the per-window cap
     * once too many codes have gone to that number (HIL-421). Either way the step answers a
     * plain success and the surface advances to the code step, so a capped number reaches a
     * code screen for a message that is not coming until the window turns over; there is no
     * resend control on the profile to say so. No duplicate-phone check here: a number is
     * not tested for an owner until the code proves possession of it.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileAddSmsRequestActionDTO $dto Phone to send the code to
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the phone is not a valid number
     * @throws EmptyValueException When the normalized identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When the verification query fails
     */
    public function requestSmsAdd(string $acceptKey, ProfileAddSmsRequestActionDTO $dto): void
    {
        $userId = $this->actingUser($acceptKey)->userId;

        $phone = PhoneNumber::normalize($dto->phone);
        if ($phone === null) {
            throw new ValidationException(AuthMessages::INVALID_PHONE);
        }

        // The send gate's verdict - cooldown hold, cap refusal or a real send - is
        // deliberately dropped: the profile has no resend control to hand a countdown
        // or a refusal to, and a repeat here is a repeated modal submit (HIL-421).
        new VerificationService()->issue(VerificationType::SMS_ADD, $phone, $userId);
    }

    /**
     * Step 2 of adding a phone: verifies the code and attaches the number (HIL-403).
     *
     * The submitted code is verified against the `sms_add` challenge; a missing, expired or
     * wrong code - or a challenge minted for a different person than this session's (defence
     * in depth against a swapped phone) - is refused with the same generic message. On
     * success a verified `sms` identity is attached to the person; the new row reaches every
     * connection through the identities projection re-emit. A phone already used by any
     * identity is refused and the existing link is never moved.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileAddSmsConfirmActionDTO $dto Phone and the code it received
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the phone or code is invalid or the phone is already in use
     * @throws HilosException When a verification or identity query fails
     */
    public function confirmSmsAdd(string $acceptKey, ProfileAddSmsConfirmActionDTO $dto): void
    {
        $userId = $this->actingUser($acceptKey)->userId;

        $phone = PhoneNumber::normalize($dto->phone);
        $verifiedUserId = $phone === null
            ? null
            : new VerificationService()->verify(VerificationType::SMS_ADD, $phone, $dto->code);
        if ($phone === null || $verifiedUserId === null || $verifiedUserId !== $userId) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        try {
            Hilos::$db->identities->createSmsIdentity($userId, $phone);
        } catch (DuplicateValueException) {
            throw new ValidationException(AuthMessages::PHONE_IN_USE);
        } catch (EmptyValueException) {
            throw new ValidationException(AuthMessages::INVALID_PHONE);
        }
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
     * whose cooldown and cap can drop the request silently (HIL-421); either way the step
     * answers a plain success, with no resend control on the profile to report the difference.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileAddPasswordRequestActionDTO $dto Address to send the code to
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the email is malformed or already verified by another account
     * @throws EmptyValueException When the normalized identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws HilosException When a verification or identity query fails
     */
    public function requestPasswordAdd(string $acceptKey, ProfileAddPasswordRequestActionDTO $dto): void
    {
        $userId = $this->actingUser($acceptKey)->userId;

        $email = strtolower($dto->email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(AuthMessages::INVALID_EMAIL);
        }

        $ownerId = Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($ownerId !== null && $ownerId !== $userId) {
            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        }

        // Same as the phone step: no resend control on the profile, so neither the
        // countdown nor the cap refusal has anywhere to go (HIL-421).
        new VerificationService()->issue(VerificationType::EMAIL_ADD, $email, $userId);
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
     * message. Uniqueness is re-checked after the code (a magic-link-verified collision on
     * the same email would slip past the password-scoped duplicate guard of the write)
     * before the write. On success a verified `password` identity is attached on the
     * now-proven email and the password-updated signal (added) is fanned to all the
     * person's connections; the new identity also arrives over the projection re-emit.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ProfileAddPasswordConfirmActionDTO $dto Address, the code it received, and the new password
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValueTooShortException When the password is shorter than the policy minimum
     * @throws ValidationException When the account already has a password, the code is
     *     invalid or expired, or the email is already in use
     * @throws InvalidArgumentException When the password-updated signal cannot be named or queued
     * @throws HilosException When a verification or identity query fails
     */
    public function confirmPasswordAdd(string $acceptKey, ProfileAddPasswordConfirmActionDTO $dto): void
    {
        $userId = $this->actingUser($acceptKey)->userId;

        // Nothing to be unchanged from: this flow only ever adds a password to an account
        // that has none, which is what the refusal below it enforces.
        PasswordPolicy::assertValid($dto->newPassword, false);

        if (Hilos::$db->identities->findPasswordByUser($userId) !== null) {
            throw new ValidationException(AuthMessages::ALREADY_HAS_PASSWORD);
        }

        $email = strtolower($dto->email);
        $verifiedUserId = new VerificationService()->verify(VerificationType::EMAIL_ADD, $email, $dto->code);
        if ($verifiedUserId === null || $verifiedUserId !== $userId) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        $ownerId = Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($ownerId !== null && $ownerId !== $userId) {
            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        }

        try {
            Hilos::$db->identities->createPasswordIdentity($userId, $email, $dto->newPassword)->markVerified();
        } catch (DuplicateValueException) {
            throw new ValidationException(AuthMessages::EMAIL_IN_USE);
        } catch (EmptyValueException) {
            throw new ValidationException(AuthMessages::INVALID_EMAIL);
        }

        $this->fanPasswordUpdated($userId, ProfilePasswordUpdatedSignalData::MODE_ADDED);
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

    /**
     * Fans the password-updated signal to every socket the person has open.
     *
     * Every tab, not only the one that saved: a change moves nothing the other tabs can see,
     * and any of them may show the confirmation.
     *
     * @param int $userId Account whose secret changed
     * @param string $mode Whether the password was added or changed, a {@see ProfilePasswordUpdatedSignalData} mode
     * @throws InvalidArgumentException When the signal cannot be named or queued
     */
    private function fanPasswordUpdated(int $userId, string $mode): void
    {
        foreach (Hilos::$rt?->sessionConnectionsSource()?->findByUser($userId) ?? [] as $connection) {
            $this->library->sendToUser(
                HilosSignalConstants::PROFILE_PASSWORD_UPDATED,
                $connection->acceptKey,
                new ProfilePasswordUpdatedSignalData($mode),
            );
        }
    }
}
