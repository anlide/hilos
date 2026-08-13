<?php

declare(strict_types=1);

namespace Hilos\Auth\Registration;

use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Random\RandomException;

/**
 * RegistrationReservationService - holds an identifier while its code travels (HIL-415).
 *
 * The mechanism behind reserve-on-submit registration: submitting the form no
 * longer creates an account, it RESERVES the address for a TTL and sends one
 * confirmation code; the account is created only when that code comes back. What
 * this buys is that an abandoned registration frees its address by itself, and
 * that a half-finished one cannot sit in the users table as an account nobody
 * proved they own.
 *
 * It owns only the hold and the credential it carries. The code is the existing
 * {@see VerificationService} ({@see VerificationType::REGISTER_CONFIRM}, no owning
 * user yet), which already provides the resend cooldown, the attempt ceiling,
 * single-use, and "one active challenge per identifier" - so "one pending
 * registration = one code" is not re-implemented here, it is inherited. The
 * reservation TTL is the code TTL for the same reason: two settings that must
 * always agree are one setting.
 *
 * The USER is the project's, always ({@see HilosDbContext} carries no users
 * collection - every Hilos project owns its own). So the split is: the caller mints
 * the user once the code has proven the address, and {@see confirmInto()} moves the
 * reserved credential into a verified identity for it and drops the hold. The
 * credential never passes through the caller.
 *
 * Sibling leaves reuse this service rather than repeat it: the magic link
 * (HIL-417) reserves on send, phone registration (HIL-486) reserves a number.
 */
final class RegistrationReservationService
{
    /**
     * Finds the live hold on an identifier.
     *
     * The read every entry point starts from: a hold means a pending registration
     * whose code is already out, which is what turns a second submit of the same
     * address into a converge instead of a second letter.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @return ?ObjectRegistrationReservation Live reservation, or null when the identifier is free
     * @throws DatabaseException When a reservation query fails
     * @throws LogicException When the reservations object collection is unavailable
     */
    public function findActive(string $identifier): ?ObjectRegistrationReservation
    {
        return $this->collection()->findActive($identifier);
    }

    /**
     * Reserves an identifier for a registration and sends its one confirmation code.
     *
     * Idempotent per identifier, deliberately: a hold that already exists is left
     * exactly as it is and NO code is sent, because the sessions submitting that
     * address converge on the code that is already in the inbox (a second letter
     * would also void the first code and strand whoever is typing it). That is also
     * why losing the insert race is not an error here - the winner's hold and code
     * stand, and the loser is on the same converged path.
     *
     * The credential belongs to whoever reserved FIRST and is never overwritten by
     * a later submit; the account is created with it. That is a deliberate,
     * owner-accepted property of this design, not an oversight: TTL and the send
     * limiters (HIL-420/421) are what bound it.
     *
     * @param string $type Reserving method (see IdentityType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?string $plainSecret Plaintext credential the account will get, or null for a method that carries none
     * @throws EmptyValueException When identifier is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws DatabaseException When a reservation or verification query fails
     * @throws LogicException When the reservations or verifications object collection is unavailable
     */
    public function reserve(string $type, string $identifier, ?string $plainSecret): void
    {
        if ($identifier === '') {
            throw new EmptyValueException('Reservation identifier is required');
        }

        $collection = $this->collection();
        if ($collection->findActive($identifier) !== null) {
            return;
        }

        try {
            $collection->createReservation($type, $identifier, $plainSecret, $this->ttlSeconds());
        } catch (DuplicateValueException) {
            return;
        }

        new VerificationService()->issue(VerificationType::REGISTER_CONFIRM, $identifier, null);
    }

    /**
     * Pushes the hold out so it outlives a freshly re-sent code.
     *
     * Called on the resend path: the code is re-issued (throttled by the
     * verification layer) and the hold follows it, so an address is never freed
     * under someone who is still waiting for the letter. A free identifier is a
     * no-op - there is no hold to extend, and the caller answers the expired
     * reservation on its own branch.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @throws DatabaseException When a reservation query fails
     * @throws LogicException When the reservations object collection is unavailable
     */
    public function extendTo(string $identifier): void
    {
        $this->collection()->extendTo($identifier, date('Y-m-d H:i:s', time() + $this->ttlSeconds()));
    }

    /**
     * Verifies a submitted confirmation code against the reservation's challenge.
     *
     * Thin by design: the challenge, its attempt ceiling and its single use live in
     * {@see VerificationService}, and this only fixes the type so no caller has to
     * know which one registration uses. A wrong, expired, exhausted or absent code
     * all answer false with nothing to tell them apart.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $code Submitted plaintext code
     * @return bool True when the code matched and was consumed
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     */
    public function verifyCode(string $identifier, string $code): bool
    {
        return new VerificationService()->verifyCode(VerificationType::REGISTER_CONFIRM, $identifier, $code);
    }

    /**
     * Lands a confirmed reservation into a user: verified identity, hold released.
     *
     * The one operation that turns a proven address into an account's credential.
     * The identity is created from the hash the reservation has carried since the
     * submit - moved, never re-hashed and never handed to the caller - and it is
     * created VERIFIED: the code that got here is the proof of ownership that the
     * old "register now, confirm later" flag used to wait for.
     *
     * Takes the reservation the caller already loaded rather than re-reading it, so
     * the row cannot be swept between the two reads; the caller is expected to mint
     * the user only after {@see verifyCode()} answered true, so a wrong code never
     * leaves a user behind.
     *
     * @param ObjectRegistrationReservation $reservation Reservation whose code was just confirmed
     * @param int $userId Freshly minted user the identity belongs to
     * @throws LogicException When the reservation carries no credential to move
     * @throws DuplicateValueException When the identifier gained a password identity meanwhile
     * @throws EmptyValueException When the reservation holds an empty identifier
     * @throws DatabaseException When an identity or reservation query fails
     */
    public function confirmInto(ObjectRegistrationReservation $reservation, int $userId): void
    {
        $identifier = $reservation->identifier;
        $secretHash = $reservation->readSecretHash();
        if ($secretHash === null) {
            throw new LogicException("Reservation for {$identifier} carries no credential");
        }

        $identity = $this->identities()->createPasswordIdentityWithHash($userId, $identifier, $secretHash);
        $identity->markVerified();

        $this->collection()->consume($identifier);
    }

    /**
     * Resolves the framework-owned reservations object collection.
     *
     * @return ObjectRegistrationReservations Reservation persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     */
    private function collection(): ObjectRegistrationReservations
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::registrationReservations);
        if (!$collection instanceof ObjectRegistrationReservations) {
            throw new LogicException('Registration reservations object collection is not configured');
        }

        return $collection;
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

    /**
     * The hold lasts exactly as long as the code that was sent for it.
     *
     * There is no reservation TTL setting of its own: an address freed while its
     * code is still valid, or held after the code died, would both be bugs nobody
     * could configure their way out of.
     *
     * @return int Configured code time-to-live in seconds
     */
    private function ttlSeconds(): int
    {
        return Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_TTL_SEC);
    }
}
