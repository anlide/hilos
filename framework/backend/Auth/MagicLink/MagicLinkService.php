<?php

declare(strict_types=1);

namespace Hilos\Auth\MagicLink;

use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Verification\VerificationSendOutcome;
use Hilos\Auth\Verification\VerificationService;
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
 * MagicLinkService - the one link that both signs in and registers (HIL-417).
 *
 * Passwordless email in a single ceremony: an address is asked for, a link goes to
 * it, and clicking that link ends with the person signed in - whether or not they
 * had an account when they asked. What makes that possible is that a FREE address
 * is held by a reservation at send time ({@see RegistrationReservationService::hold()},
 * type {@see IdentityType::MAGIC_LINK}, no credential), so the address cannot be
 * taken by someone else while the letter is in flight, and the account is minted
 * only once the link comes back.
 *
 * The token is issued with NO owning user, always, and that is the deliberate
 * difference from HIL-283's account-bound link: between the send and the click an
 * account may appear on that address (an OAuth sign-up over the live hold) or the
 * address may become free again, so the link proves ownership of the ADDRESS and
 * the account is resolved at click time. Hence {@see verifyToken()} answers a
 * yes/no rather than a user id.
 *
 * The letter carries TWO ways back (HIL-606): the link, and a six-digit companion code
 * for the person whose mail is on a phone while the sign-in screen waits on a laptop.
 * They are separate challenges with separate attempt ceilings, so they get separate
 * doors ({@see verifyToken()}, {@see verifyCode()}); answering either one voids the
 * other, which is what makes the letter single-use as it claims.
 *
 * The service owns only the mechanism. Who the click signs in - existing account,
 * or one created from the landed reservation - is the calling flow's decision,
 * exactly as it is for registration codes: the USER belongs to the project.
 */
final class MagicLinkService
{
    /**
     * Sends a sign-in link to an address, holding it first when it is free.
     *
     * One path for both intents on purpose: the caller does not have to know
     * whether the address has an account, and neither does the letter - the same
     * link means "sign in" for a known address and "finish registering" for a free
     * one. The hold is skipped for an address that already has an account, since
     * there is nothing left to reserve.
     *
     * The answer is the send gate's own ({@see VerificationService::issue()}): sent,
     * held by the cooldown, or refused by the window cap. It says nothing about
     * whether the address exists - both branches issue the same challenge - so the
     * caller may repeat it to the surface honestly.
     *
     * @param string $email Address to send the link to (normalized here)
     * @return VerificationSendOutcome Whether the link went out, and the seconds until the next may
     * @throws EmptyValueException When the normalized address is empty
     * @throws RandomException When the platform CSPRNG cannot produce a token
     * @throws DatabaseException When an identity, reservation or verification query fails
     * @throws LogicException When a framework object collection is unavailable
     * @throws EnvException When a reservation or verification env key is missing, outside the
     *   catalog, or of the wrong type
     * @throws ValidationException When the link cannot be delivered to the address
     * @throws InvalidArgumentException When the transport's send signal cannot be named or queued
     */
    public function send(string $email): VerificationSendOutcome
    {
        $identifier = mb_strtolower(trim($email));

        if ($this->identities()->findUserIdByVerifiedEmail($identifier) === null) {
            new RegistrationReservationService()->hold(IdentityType::MAGIC_LINK, $identifier, null);
        }

        return new VerificationService()->issue(VerificationType::MAGIC_LINK, $identifier, null);
    }

    /**
     * Checks the token a clicked link came back with, and spends it.
     *
     * Thin by design, the sibling of {@see RegistrationReservationService::verifyCode()}:
     * the challenge, its expiry, its attempt ceiling and its single use live in
     * {@see VerificationService}, and this only fixes the type so no caller has to
     * know which one the link uses. A wrong, expired, spent or absent token all
     * answer false with nothing to tell them apart.
     *
     * The owning user is not read from the challenge - it was issued without one -
     * so a true here means the address was proven, and resolving whose account that
     * is belongs to the caller.
     *
     * A click also puts out the companion code ({@see verifyCode()}) - the letter says
     * whichever half you use, the other stops working, and this is where the letter is
     * kept honest.
     *
     * @param string $email Address the link was issued for (normalized here)
     * @param string $token Token carried back by the clicked link
     * @return bool True when the token matched the live challenge and was consumed
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function verifyToken(string $email, string $token): bool
    {
        return $this->consumeHalf(VerificationType::MAGIC_LINK, $email, $token);
    }

    /**
     * Checks the code typed on the waiting screen, and spends it (HIL-606).
     *
     * The other half of the same letter, and deliberately a separate entry point rather
     * than {@see verifyToken()} given a shorter string: the link and the code are two
     * challenges with two attempt ceilings, so a single door would have to guess which
     * one it was handed and would spend an attempt of both on every guess.
     *
     * Answers exactly what {@see verifyToken()} answers, about the same address, so the
     * caller signs the same person in whichever way they chose to prove the address.
     *
     * @param string $email Address the letter was issued for (normalized here)
     * @param string $code Companion code typed on the waiting screen
     * @return bool True when the code matched the live challenge and was consumed
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    public function verifyCode(string $email, string $code): bool
    {
        return $this->consumeHalf(VerificationType::MAGIC_LINK_CODE, $email, $code);
    }

    /**
     * Spends one half of a magic-link letter and, on success, voids the other.
     *
     * The letter promises single use, and it carries two secrets, so one of them being
     * answered has to end the other - otherwise a forwarded mail would still let its
     * reader in after the owner already signed in with it.
     *
     * Only SUCCESS voids the sibling. A wrong code, or one that burns the last permitted
     * attempt, leaves the link alone on purpose: the two ceilings exist so that a stranger
     * guessing six digits cannot invalidate a letter they never received.
     *
     * @param string $type Half being answered (see VerificationType)
     * @param string $email Address the letter was issued for (normalized here)
     * @param string $secret Token or code the person came back with
     * @return bool True when the secret matched the live challenge and was consumed
     * @throws DatabaseException When a verification query fails
     * @throws LogicException When the verifications object collection is unavailable
     * @throws EnvException When the attempt-ceiling env key is missing, outside the catalog,
     *   or not an int
     */
    private function consumeHalf(string $type, string $email, string $secret): bool
    {
        $identifier = mb_strtolower(trim($email));
        $verifications = new VerificationService();

        if (!$verifications->verifyCode($type, $identifier, $secret)) {
            return false;
        }

        $verifications->consumeActive($this->siblingOf($type), $identifier);

        return true;
    }

    /**
     * The other half of the letter a type belongs to.
     *
     * @param string $type Half being answered (see VerificationType)
     * @return string The half to void once this one succeeded
     */
    private function siblingOf(string $type): string
    {
        return $type === VerificationType::MAGIC_LINK
            ? VerificationType::MAGIC_LINK_CODE
            : VerificationType::MAGIC_LINK;
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
