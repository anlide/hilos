<?php

declare(strict_types=1);

namespace Hilos\Auth\Recovery;

use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Auth\Verification\VerificationSendOutcome;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Random\RandomException;

/**
 * PasswordRecoveryService - resetting a password across three steps (HIL-416).
 *
 * The mirror image of {@see RegistrationReservationService}, and deliberately so: one
 * address holds one code, several sessions may be waiting on it, and the first one to
 * finish settles it for the rest. What differs is where the weight sits. Registration
 * holds a durable reservation because an address must not be taken twice; recovery
 * holds nothing of its own - the account already exists, and the only thing being
 * proven is that whoever is asking can read its mailbox.
 *
 * Hence the shape of the three calls, which is the shape of the flow:
 * {@see requestCode()} sends, {@see acceptCode()} proves WITHOUT spending, and
 * {@see complete()} spends and writes the new secret. The split of the middle from the
 * last is the whole reason this service exists rather than one more call on
 * {@see VerificationService}: the code screen and the password screen are two round
 * trips, and a code spent on the first would leave the second holding nothing.
 *
 * What guards the gap between them is not here but around it: the grant lives on the
 * session (the recovery waiters collection), and its lifetime is the unspent code's,
 * so there is no second clock to disagree with the first. And the ceiling still counts
 * - a wrong code costs an attempt on the accepting step exactly as it does on a
 * consuming one.
 *
 * Only `password` identities: an address whose account was built by a link, a provider
 * or a phone has no password to reset, and this refuses it rather than mailing a code
 * that could not lead anywhere ({@see requestCode()} answers null and the surface says
 * so out loud - the detection in front of the form already told the person as much).
 */
final class PasswordRecoveryService
{
    /**
     * Sends a reset code to an address that has a password to reset.
     *
     * The refusal comes back as null rather than as an exception because it is an
     * ordinary answer of this flow, not a failure of it: the surface words it, and
     * the words belong to the surface (HIL-414). Everything else - the cooldown, the
     * per-window cap, the single active challenge per address - is the verification
     * layer's and is not re-implemented here.
     *
     * @param string $email Normalized address to recover (lowercased)
     * @return ?VerificationSendOutcome Send verdict, or null when the address has no password to reset
     * @throws EmptyValueException When the address is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws DatabaseException When an identity or verification query fails
     * @throws LogicException When the identities or verifications object collection is unavailable
     * @throws EnvException When a send-gate or challenge env key is missing, outside the
     *   catalog, or of the wrong type
     * @throws ValidationException When the code was issued for a target the transport refuses
     * @throws InvalidArgumentException When the transport's send signal cannot be named or queued
     */
    public function requestCode(string $email): ?VerificationSendOutcome
    {
        $userId = $this->passwordUserId($email);
        if ($userId === null) {
            return null;
        }

        return new VerificationService()->issue(VerificationType::PASSWORD_RESET, $email, $userId);
    }

    /**
     * Whether the address still has a reset code that a person could be typing.
     *
     * What tells a mistake from an expiry. A wrong code leaves the person on the code
     * screen to try again; a recovery whose code is gone - never asked for, timed out,
     * or spent by whoever got there first - is not their mistake and rolls the surface
     * back to the address field instead. The caller asks this before it judges the code
     * it was given, since a submitted code cannot tell the two apart on its own.
     *
     * @param string $email Normalized address being recovered (lowercased)
     * @return bool True when a live reset code is waiting for this address
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function hasLiveCode(string $email): bool
    {
        return new VerificationService()->hasActive(VerificationType::PASSWORD_RESET, $email);
    }

    /**
     * Checks a submitted reset code and leaves it unspent.
     *
     * What buys the password step. Thin by design: the challenge, its attempt ceiling
     * and its lifetime live in {@see VerificationService}, and this only fixes the type
     * so no caller has to know which one recovery uses. A wrong, expired, exhausted or
     * absent code all answer false with nothing to tell them apart - the caller
     * distinguishes them the one way that matters to the person, by asking whether any
     * challenge is still live at all.
     *
     * @param string $email Normalized address being recovered (lowercased)
     * @param string $code Submitted plaintext code
     * @return bool True when the code matched the live challenge
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function acceptCode(string $email, string $code): bool
    {
        return new VerificationService()->matchCode(VerificationType::PASSWORD_RESET, $email, $code);
    }

    /**
     * Spends the code and writes the new secret, answering whose account it was.
     *
     * The one operation that ends a recovery, and the order inside it is the mechanism:
     * the identity is resolved first (so a code is never spent on an account that
     * cannot receive the password), then the code is spent, and only then is the secret
     * written. Spending it is what settles the address for everyone else waiting on it
     * - the challenge is the recovery's single-use ticket, and there is exactly one.
     *
     * A null answer means this session is too late: another one already finished the
     * reset, or the code expired while the password screen sat open. The caller owes
     * that session the same thing it owes the ones it is about to converge - the
     * identifier step, and the news that the password is already changed. The
     * plaintext is hashed inside the identity layer and never reaches this one.
     *
     * @param string $email Normalized address being recovered (lowercased)
     * @param string $newPassword New plaintext password to store
     * @return ?int User the password now belongs to, or null when the reset can no longer be completed
     * @throws DatabaseException When an identity or verification query fails
     * @throws LogicException When the identities or verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function complete(string $email, string $newPassword): ?int
    {
        $identity = $this->identities()->findByIdentity(IdentityType::PASSWORD, $email);
        $userId = $identity?->userId;
        if ($identity === null || $userId === null) {
            return null;
        }

        if (!new VerificationService()->consumeActive(VerificationType::PASSWORD_RESET, $email)) {
            return null;
        }

        $identity->setPassword($newPassword);

        return $userId;
    }

    /**
     * The account behind an address that can be recovered, if any.
     *
     * @param string $email Normalized address (lowercased)
     * @return ?int Owning user id of the address's password identity, or null when it has none
     * @throws DatabaseException When an identity query fails
     * @throws LogicException When the identities object collection is unavailable
     */
    private function passwordUserId(string $email): ?int
    {
        return $this->identities()->findByIdentity(IdentityType::PASSWORD, $email)?->userId;
    }

    /**
     * Resolves the framework-owned identities object collection.
     *
     * @return ObjectIdentities Identity persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     */
    private function identities(): ObjectIdentities
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::identities);
        if (!$collection instanceof ObjectIdentities) {
            throw new LogicException('Identities object collection is not configured');
        }

        return $collection;
    }
}
