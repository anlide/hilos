<?php

declare(strict_types=1);

namespace Hilos\Auth\Registration;

use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Verification\VerificationSendOutcome;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Helpers\TimeHelper;
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
 * It owns only the hold and the proof standing on it. The code is the existing
 * {@see VerificationService} ({@see VerificationType::REGISTER_CONFIRM}, no owning
 * user yet), which already provides the resend cooldown, the attempt ceiling,
 * single-use, and "one active challenge per identifier" - so "one pending
 * registration = one code" is not re-implemented here, it is inherited. The
 * reservation TTL is the code TTL for the same reason: two settings that must
 * always agree are one setting.
 *
 * The hold belongs to the BROWSER that made it (HIL-608), not to the address: one
 * session leads one registration at a time, and several sessions may legitimately be
 * registering the same address at once. That is what closes the capture the address
 * key allowed - a letter answered in another browser lands nothing of this one's, so
 * a stranger's password can never end up inside the account the owner of the inbox
 * creates. Several browsers may prove one address; the first to SAVE A PASSWORD on
 * it wins it, and the rest are told it is taken (HIL-825).
 *
 * The USER is the project's, always ({@see HilosDbContext} carries no users
 * collection - every Hilos project owns its own). So the split is: the caller mints
 * the user once the address is proven, and {@see confirmProvenAddress()} gives it a
 * verified identity and drops the holds.
 *
 * A password registration is TWO proofs and not one (HIL-825): the code proves the
 * address and leaves {@see markProven()} on the hold, and the password submitted
 * afterwards is what lands the account. So the hold carries no credential at any
 * point - the plaintext arrives at the landing and is hashed into the identity
 * there, and an abandoned registration leaves no secret behind for anyone.
 *
 * Sibling leaves reuse this service rather than repeat it: the magic link
 * (HIL-417) reserves on send, phone registration (HIL-486) reserves a number.
 */
final class RegistrationReservationService
{
    /**
     * Finds the live hold this browser is leading.
     *
     * The read every entry point starts from: a hold means a registration this
     * session started and whose code is already out, which is what brings a returning
     * tab back to its own code screen instead of the identifier field. It says
     * nothing about what other browsers are registering, deliberately - answering
     * about somebody else's hold would make this an oracle for "is anyone signing up
     * with this address".
     *
     * @param string $sessionToken Session cookie token of the asking browser
     * @return ?ObjectRegistrationReservation Live reservation, or null when this browser holds none
     * @throws DatabaseException When a reservation query fails
     * @throws LogicException When the reservations object collection is unavailable
     * @throws DbCollectionNotReadableException When nothing here reads the reservations collection, or its readiness is on its way
     */
    public function findActiveForSession(string $sessionToken): ?ObjectRegistrationReservation
    {
        return $this->collection()->findActiveForSession($sessionToken);
    }

    /**
     * Holds an identifier for this browser's registration, sending nothing.
     *
     * The hold on its own, split out of {@see reserve()} because the magic link
     * (HIL-417) reserves an address whose letter is a LINK, not a registration
     * code: it holds the address and then issues its own challenge, so a
     * `register_confirm` code sent from in here would be a second letter about a
     * registration nobody started.
     *
     * There is always a hold to answer with (HIL-608): this session either gets the
     * one it just made or the one it just refreshed, so a caller never has to tell
     * "mine" from "somebody else's, joined". A submit of a DIFFERENT address ends
     * this browser's previous registration - one browser, one attempt - and another
     * browser's hold on the same address is not touched at all: they are racing, and
     * the race is settled by whoever finishes the registration first - by saving a
     * password on the address (HIL-825) - not by who submitted or proved it first.
     *
     * @param string $type Reserving method (see IdentityType)
     * @param string $sessionToken Session cookie token of the browser leading this registration
     * @param string $identifier Normalized identifier (lowercased email)
     * @return ObjectRegistrationReservation This browser's hold on the identifier
     * @throws EmptyValueException When identifier or session token is empty
     * @throws DatabaseException When a reservation query fails
     * @throws LogicException When the reservations object collection is unavailable, or a racing
     *   insert left no hold to answer with
     * @throws EnvException When the reservation TTL key is missing, outside the catalog, or
     *   not an int
     * @throws DbCollectionNotReadableException When nothing here reads the reservations collection, or its readiness is on its way
     */
    public function hold(
        string $type,
        string $sessionToken,
        string $identifier,
    ): ObjectRegistrationReservation {
        if ($identifier === '') {
            throw new EmptyValueException('Reservation identifier is required');
        }

        $collection = $this->collection();

        try {
            return $collection->createReservation($type, $sessionToken, $identifier, $this->ttlSeconds());
        } catch (DuplicateValueException) {
            // Two sockets of one browser inserting at the same instant. There is one
            // registration per browser and this is it, whichever socket wrote it.
            return $collection->findActiveForSession($sessionToken)
                ?? throw new LogicException("Registration hold for {$identifier} vanished under a racing insert");
        }
    }

    /**
     * Reserves an identifier for this browser and sends its confirmation code.
     *
     * The password-registration entry point: {@see hold()} plus the code that proves
     * the address. The letter is asked for EVERY time a hold is made or refreshed
     * (HIL-608), and the send gate decides whether one actually goes out - it is the
     * gate that owns "not a second letter within the cooldown", and it owns it per
     * ADDRESS, so a second browser registering the same address is answered with the
     * honest wait rather than with silence it would read as a lost letter.
     *
     * The gate's verdict is returned rather than swallowed for the same reason: the
     * surface draws a countdown from it, and the one thing it must never do is
     * promise a letter the cap refused.
     *
     * @param string $type Reserving method (see IdentityType)
     * @param string $sessionToken Session cookie token of the browser leading this registration
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?string $progressTicket Ticket the transport reports this letter's steps against (HIL-826)
     * @return VerificationSendOutcome Whether the code went out, and the seconds until the next may
     * @throws EmptyValueException When identifier or session token is empty
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws DatabaseException When a reservation or verification query fails
     * @throws LogicException When the reservations or verifications object collection is unavailable,
     *   or a racing insert left no hold to answer with
     * @throws EnvException When a reservation or verification env key is missing, outside the
     *   catalog, or of the wrong type
     * @throws ValidationException When the confirmation code cannot be delivered to the identifier
     * @throws InvalidArgumentException When the transport's send signal cannot be named or queued
     * @throws DbCollectionNotReadableException When nothing here reads the reservations or verifications collection, or its readiness is on its way
     */
    public function reserve(
        string $type,
        string $sessionToken,
        string $identifier,
        ?string $progressTicket = null,
    ): VerificationSendOutcome {
        $this->hold($type, $sessionToken, $identifier);

        return new VerificationService()->issue(
            VerificationType::REGISTER_CONFIRM,
            $identifier,
            null,
            $progressTicket,
        );
    }

    /**
     * Finds the hold this browser has already proved with a code.
     *
     * The read the password step stands on (HIL-825): the address it saves a password
     * for comes off this row and never off the payload, so a password screen cannot
     * name an account whose inbox this browser has not proven. A live hold that was
     * never proved answers null - that browser is still on the code screen.
     *
     * @param string $sessionToken Session cookie token of the asking browser
     * @return ?ObjectRegistrationReservation Proved live hold, or null when this browser holds none
     * @throws DatabaseException When a reservation query fails
     * @throws LogicException When the reservations object collection is unavailable
     * @throws DbCollectionNotReadableException When nothing here reads the reservations collection, or its readiness is on its way
     */
    public function findProvenForSession(string $sessionToken): ?ObjectRegistrationReservation
    {
        $reservation = $this->collection()->findActiveForSession($sessionToken);

        return $reservation?->isProven() === true ? $reservation : null;
    }

    /**
     * Marks this browser's hold on an address as proved, and gives it the full TTL again.
     *
     * What an accepted registration code now does instead of creating the account
     * (HIL-825). The proof is written on the hold rather than left on the code, which
     * is what lets the code be spent in the same breath: the person still has a
     * password to choose, and a second screen standing on a single-use code would be
     * a screen that refuses its own submit.
     *
     * The extension is not bookkeeping either. Until the code came back the TTL held a
     * letter in transit; from here it holds the ADDRESS while a person invents a
     * password, and a hold that ran out on its fourteenth minute would take that
     * address away one minute into the thinking - a loss caused by us and not by them.
     *
     * A session holding nothing, or holding another address, is refused rather than
     * silently proved: the caller has already checked the hold, so a mismatch here
     * means it went away in between, and the honest answer is the expired one.
     *
     * @param string $sessionToken Session cookie token of the browser that proved the code
     * @param string $identifier Normalized identifier the code proved (lowercased email)
     * @return bool True when the hold was marked, false when this browser holds no such live hold
     * @throws DatabaseException When a reservation query fails
     * @throws LogicException When the reservations object collection is unavailable
     * @throws EnvException When the reservation TTL key is missing, outside the catalog, or not an int
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws DbCollectionNotReadableException When nothing here reads the reservations collection, or its readiness is on its way
     */
    public function markProven(string $sessionToken, string $identifier): bool
    {
        $reservation = $this->collection()->findActiveForSession($sessionToken);
        if ($reservation === null || $reservation->identifier !== $identifier) {
            return false;
        }

        $reservation->markCodeAccepted(TimeHelper::getSqlDateTime());
        $this->extendTo($sessionToken);

        return true;
    }

    /**
     * Pushes the hold out so it outlives a freshly re-sent code.
     *
     * Called on the resend path: the code is re-issued (throttled by the
     * verification layer) and this browser's hold follows it, so a registration is
     * never dropped under someone who is still waiting for the letter. A session
     * holding nothing is a no-op - there is no hold to extend, and the caller answers
     * the expired reservation on its own branch.
     *
     * @param string $sessionToken Session cookie token of the browser that re-sent
     * @throws DatabaseException When a reservation query fails
     * @throws LogicException When the reservations object collection is unavailable
     * @throws EnvException When the reservation TTL key is missing, outside the catalog, or
     *   not an int
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws DbCollectionNotReadableException When nothing here reads the reservations collection, or its readiness is on its way
     */
    public function extendTo(string $sessionToken): void
    {
        $this->collection()->extendTo($sessionToken, date('Y-m-d H:i:s', time() + $this->ttlSeconds()));
    }

    /**
     * Drops a hold that has nothing left to land (HIL-417).
     *
     * The counterpart of {@see confirmProvenAddress()} for the registration that ended
     * as a sign-in: a magic link clicked on an address that gained an account while the
     * letter travelled signs that account in, and the hold it leaves behind names a
     * registration nobody is running any more. The sweeper would collect it eventually,
     * but only after the TTL - and until then this browser's next attempt would be
     * answered with a code screen for an address it is no longer registering.
     *
     * A session holding nothing is a no-op; there is no hold to drop.
     *
     * @param string $sessionToken Session cookie token of the browser whose hold is over
     * @throws DatabaseException When the reservation delete query fails
     * @throws LogicException When the reservations object collection is unavailable
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws DbCollectionNotReadableException When nothing here reads the reservations collection, or its readiness is on its way
     */
    public function release(string $sessionToken): void
    {
        $this->collection()->consume($sessionToken);
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
     * Lands a just-proven address into a user and clears every hold on it.
     *
     * The one door a proven address goes through (HIL-608), whichever way it was
     * proven and whoever proved it. It answers three questions at once, and they used
     * to be answered separately by each of the three call sites: what identity the new
     * account gets, what happens to the holds of the browsers that were racing this
     * one, and who has to be told.
     *
     * WHOSE hold lands is the whole point of the leaf: only the one this session
     * started, and only when it names the address just proven. A proof answered in a
     * browser that reserved nothing - a link opened on a fresh tab, or an identifier
     * whose own hold ran out while the letter travelled - still ends in an account,
     * because what came back is itself the proof of the identifier and "your
     * registration expired" is untrue about a live letter; that account simply gets
     * the secret-less identity {@see landWithoutHold()} names and none of anybody's
     * password.
     *
     * WHAT lands is decided by the password the CALLER brings, not by the hold: the
     * hold carries no credential at all since HIL-825, and a registration that ends on
     * a password screen hands the plaintext straight through to the identity that is
     * being written. With no password - a link, a number - the hold's own type names
     * the secret-less identity instead, unless the caller names one over it: the way
     * past the password screen (HIL-1008) ends a hold taken for a password, and what it
     * earns is the mailed link the surface offered, not the hold's own type. The
     * identity is created VERIFIED either way:
     * whatever came back, code or link, is the proof of ownership that the old
     * "register now, confirm later" flag used to wait for.
     *
     * The losing holds go here rather than at the caller, in the same breath as the
     * landing: the address has an account now, so their registrations cannot finish,
     * and leaving them would refuse a second attempt on the address for the whole TTL.
     * Their sessions are RETURNED because they have to be told they lost, and this is
     * the last moment anyone knows who they were.
     *
     * @param string $sessionToken Session cookie token of the browser that proved the address
     * @param string $identifier Normalized identifier just proven (lowercased email)
     * @param int $userId Freshly minted user the identity belongs to
     * @param ?string $plainPassword Password the account is being created with, or null for a method that carries none
     * @param ?string $landAs Identity a secret-less landing earns (see IdentityType), or null to take the hold's own type
     * @return list<string> Session tokens whose hold on the identifier this call dropped
     * @throws LogicException When the landing hold carries neither a password nor a landable type
     * @throws DuplicateValueException When the identifier gained an identity of that type meanwhile
     * @throws EmptyValueException When the identifier is empty
     * @throws InvalidFormatException When the proven identifier is neither an address nor a number
     * @throws DatabaseException When an identity or reservation query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws DbCollectionNotReadableException When nothing here reads the reservations or identities collection, or its readiness is on its way
     */
    public function confirmProvenAddress(
        string $sessionToken,
        string $identifier,
        int $userId,
        ?string $plainPassword = null,
        ?string $landAs = null,
    ): array {
        $collection = $this->collection();

        $reservation = $collection->findActiveForSession($sessionToken);
        $ownHold = $reservation !== null && $reservation->identifier === $identifier ? $reservation : null;
        if ($ownHold !== null) {
            $this->land($ownHold, $userId, $plainPassword, $landAs);
        } else {
            $this->landWithoutHold($identifier, $userId);
        }

        $losers = $collection->releaseOthers($identifier, $sessionToken);
        if ($ownHold !== null) {
            $collection->consume($sessionToken);
        }

        return $losers;
    }

    /**
     * Turns one browser's proven hold into the identity its account signs in with.
     *
     * A password submitted with the landing lands a password identity, whatever type
     * the hold was made under: it is hashed here, at the moment the account comes into
     * being, so no hash for an account that does not exist is ever stored anywhere
     * (HIL-825). Without one, the hold's own type names the secret-less identity that
     * method earns, unless the caller names one over it - which is what the way past the
     * password screen does (HIL-1008): its hold was taken for a password, and the exit it
     * was left by is the mailed link. A hold that names neither is a contradiction -
     * nothing but a submit could have written it - so it is refused rather than landed
     * into an account nobody could ever sign into.
     *
     * @param ObjectRegistrationReservation $reservation This session's hold, whose proof was just accepted
     * @param int $userId Freshly minted user the identity belongs to
     * @param ?string $plainPassword Password the account is being created with, or null for a method that carries none
     * @param ?string $landAs Identity a secret-less landing earns (see IdentityType), or null to take the hold's own type
     * @throws LogicException When the hold carries neither a password nor a landable type
     * @throws DuplicateValueException When the identifier gained an identity of that type meanwhile
     * @throws EmptyValueException When the reservation holds an empty identifier
     * @throws DatabaseException When an identity or reservation query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the identity's store or update announcement raises
     * @throws WriteNotAllowedException When no truth source in this process may write the identity row
     * @throws DbCollectionNotReadableException When nothing here reads the identities collection, or its readiness is on its way
     */
    private function land(
        ObjectRegistrationReservation $reservation,
        int $userId,
        ?string $plainPassword,
        ?string $landAs = null,
    ): void {
        $identifier = $reservation->identifier;
        if ($plainPassword !== null) {
            $this->identities()->createPasswordIdentity($userId, $identifier, $plainPassword)->markVerified();

            return;
        }

        match ($landAs ?? $reservation->type) {
            IdentityType::MAGIC_LINK => $this->identities()->createMagicLinkIdentity($userId, $identifier),
            IdentityType::SMS => $this->identities()->createSmsIdentity($userId, $identifier),
            default => throw new LogicException("Reservation for {$identifier} landed without a password"),
        };
    }

    /**
     * Gives an account the secret-less identity a proof with no hold behind it earns.
     *
     * The holdless landing: the proof arrived for an identifier this session holds
     * nothing on - a link opened on a fresh tab, or a hold that ran out in the moment
     * between the caller's check and this one. There is no reservation left to read a
     * type off, so the identity is chosen by what the proven identifier IS, through the
     * one classifier the rest of the auth surface asks ({@see IdentifierDetector::kindOf()});
     * a second reading of the same string elsewhere would be a second opinion about it.
     *
     * Choosing by kind rather than assuming an address is what keeps the phone symmetric
     * with mail: a number landed as a mailed sign-in would leave its owner with an
     * account they cannot sign into and the number still reading as free to the next
     * registration - two accounts for one person, which is the very thing this leaf
     * closes.
     *
     * @param string $identifier Normalized identifier the proof just settled (lowercased email or E.164)
     * @param int $userId Freshly minted user the identity belongs to
     * @throws InvalidFormatException When the proven identifier is neither an address nor a number
     * @throws DuplicateValueException When the identifier gained an identity of that type meanwhile
     * @throws EmptyValueException When the identifier is empty
     * @throws DatabaseException When the identity query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws DbCollectionNotReadableException When nothing here reads the identities collection, or its readiness is on its way
     */
    private function landWithoutHold(string $identifier, int $userId): void
    {
        match (IdentifierDetector::kindOf($identifier)) {
            IdentifierDetection::KIND_PHONE => $this->identities()->createSmsIdentity($userId, $identifier),
            default => $this->identities()->createMagicLinkIdentity($userId, $identifier),
        };
    }

    /**
     * Resolves the framework-owned reservations object collection.
     *
     * @return ObjectRegistrationReservations Reservation persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     * @throws DbCollectionNotReadableException When nothing here reads the reservations collection, or its readiness is on its way
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
     * @throws DbCollectionNotReadableException When nothing here reads the identities collection, or its readiness is on its way
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
        return Hilos::$env[EnvConstants::HILOS_VERIFICATION_TTL_SEC]->int();
    }
}
