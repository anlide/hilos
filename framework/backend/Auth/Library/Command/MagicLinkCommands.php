<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkCodeActionDTO;
use Hilos\Auth\Library\DTO\RequestMagicLinkActionDTO;
use Hilos\Auth\MagicLink\MagicLinkService;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * The mailed letter that both signs in and registers (HIL-283, HIL-417, HIL-622).
 *
 * ONE letter with two halves - a clickable link and a typed code - and one ending under
 * both, because whoever proved the address must become the same kind of member whichever
 * half they used. The token is issued owning nobody on purpose: who the click signs in is
 * decided at the moment it arrives, from the state the address is in then, which is what
 * lets a letter mailed to a stranger still end in a sign-in when an account appeared while
 * it travelled.
 */
final class MagicLinkCommands extends AbstractLibraryCommands
{
    /**
     * Issues an email magic-link sign-in token, always answering generically.
     *
     * The passwordless login entry (HIL-283): login-only, so it resolves the
     * account through the framework's one definition of a taken address
     * ({@see Identities::findAccountIdByEmail()}) and issues (throttled inside
     * the service) a token — no user or identity is ever created here. A free
     * address is HELD by a reservation for the life of the link (HIL-417), and the
     * hold is THIS BROWSER's since HIL-608: the letter may only finish the
     * registration of whoever asked for it. An address that already has an account
     * is not held, there being nothing left to register. The token is delivered as a
     * clickable URL assembled by the framework
     * ({@see VerificationService::issue()}), which the /auth/magic route relays back.
     *
     * The answer is HONEST now, like every other send on this surface (HIL-421 sent
     * it blind; the owner reversed that with this leaf): the real remaining cooldown,
     * and the cap refused out loud. What made the blindness worth its price was that
     * the letter went only to a known address, so the number leaked whether one
     * existed. It goes to both now, and the number says only "this address was mailed
     * recently". Silence, meanwhile, had a price of its own: the resend button
     * answered "sent" over a send the cap had refused.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param RequestMagicLinkActionDTO $dto Parsed request payload (email)
     * @return AuthFlowOutcome Moments the resend gate opens and the link dies, or the cap refusal
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws EmptyValueException When the submitted address is empty
     * @throws ValidationException When the link cannot be delivered to the address
     * @throws InvalidArgumentException When the transport's send signal cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot produce a token
     * @throws HilosException When identity lookup, the hold, or token issuing fails
     */
    public function requestMagicLink(string $acceptKey, RequestMagicLinkActionDTO $dto): AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = strtolower($dto->email);
        $outcome = new MagicLinkService()->send($email, $acting->sessionToken);

        if ($outcome->capReached) {
            return AuthFlowOutcome::refuse(AuthFlowOutcome::CODE_SEND_CAP_REACHED, AuthMessages::SEND_CAP);
        }

        // Answered for a stranger exactly as for a member: the link is issued on both
        // sides of that question, so this moment says how long the letter is good for
        // and nothing about whose inbox it went to (HIL-486).
        return AuthFlowOutcome::sent(
            $outcome->resendAt(),
            new VerificationService()->activeExpiresAt(VerificationType::MAGIC_LINK, $email),
        );
    }

    /**
     * Opens a clicked sign-in link: signs the address in, registering it if needed.
     *
     * The other half of the one link that does both (HIL-417). The token proves the
     * ADDRESS and nothing else - it was issued with no owning user, deliberately -
     * so who this click signs in is decided HERE, from the state the address is in
     * at the moment of the click rather than at the moment of the send. That is what
     * makes the letter survive the gap: an account may appear on the address while it
     * travels (an OAuth sign-up over the live hold), and a link sent to a stranger
     * still ends in a sign-in rather than a dead end.
     *
     * A token that does not open anything is answered first and under a code of its own,
     * so the return screen offers a new link instead of accusing a typo nobody made. What
     * a proven address then means - sign-in, or the registration finishing - is decided by
     * {@see signInProvenAddress()}, shared with the code half of the same letter.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ConfirmMagicLinkActionDTO $dto Parsed confirm payload (email, token)
     * @return ?AuthFlowOutcome The rollback to the address field, or null when the session holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws EmptyValueException When the display name the new account is created with is empty
     * @throws InvalidFormatException When the proven address is not a valid identifier
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When verification, the account, identity, or reservation write fails
     */
    public function confirmMagicLink(string $acceptKey, ConfirmMagicLinkActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = strtolower($dto->email);
        if (!new MagicLinkService()->verifyToken($email, $dto->token)) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_MAGIC_LINK_INVALID,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN,
                AuthMessages::MAGIC_LINK_INVALID,
            );
        }

        return $this->signInProvenAddress($acting, $email);
    }

    /**
     * Opens the letter's other half: signs the typed-in address in, registering it if needed.
     *
     * The same ceremony as {@see confirmMagicLink()}, entered from the waiting screen
     * instead of from a clicked link (HIL-606). Whoever proved the address gets the same
     * ending, which is why the whole tail is shared: a person who typed six digits rather
     * than clicking must not end up a different kind of member.
     *
     * The one difference is where a bad secret leaves the surface. A clicked link rolls back
     * to the address field, because its reader arrived on an empty tab and has nowhere else
     * to stand; a typed code refuses in place, because the person is still on the screen that
     * asked for it and has most likely just mistyped - the field, the countdown and the
     * resend button are all still right there.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ConfirmMagicLinkCodeActionDTO $dto Parsed confirm payload (email, code)
     * @return ?AuthFlowOutcome The refusal in place, or null when the session holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws EmptyValueException When the display name the new account is created with is empty
     * @throws InvalidFormatException When the proven address is not a valid identifier
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When verification, the account, identity, or reservation write fails
     */
    public function confirmMagicLinkCode(string $acceptKey, ConfirmMagicLinkCodeActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = strtolower($dto->email);
        if (!new MagicLinkService()->verifyCode($email, $dto->code)) {
            return AuthFlowOutcome::refuse(
                AuthFlowOutcome::CODE_MAGIC_LINK_INVALID,
                AuthMessages::MAGIC_LINK_INVALID,
            );
        }

        return $this->signInProvenAddress($acting, $email);
    }

    /**
     * Signs this session in on an address the ceremony just proved.
     *
     * The shared ending of both halves of the letter (HIL-606). It is one method and not
     * two copies because the branch it picks decides WHO the person becomes, and two copies
     * would eventually mean that clicking a link and typing its code produce different
     * members - a difference nothing in the ceremony justifies.
     *
     * TWO answers now, not three (HIL-608). An account owns the address, and this session
     * becomes that account - the hold this browser may still carry on that very address is
     * dropped, since nobody is registering an address that already resolved. Otherwise the
     * letter finishes a registration: a user, the identity this browser's hold earns,
     * whatever the project writes about a new member, and a signed-in session.
     *
     * The third answer - "your registration expired, start again" - is gone, and its
     * disappearance is the leaf's point. A live letter IS the proof of the inbox, so
     * refusing it because the reader's own hold ran out (or because they never made one,
     * having opened the link on a fresh tab) told a truthful person something untrue. They
     * get an account with the mailed sign-in and nobody else's password.
     *
     * An address held by an UNVERIFIED password identity resolves to that account and the
     * identity is marked verified here: answering its mail is exactly the proof it was
     * waiting for, refusing would strand somebody who does not know the password, and
     * registering a second account for one person is the defect this closes.
     *
     * @param ActingSession $acting Browser that proved the address
     * @param string $email Lowercased address the ceremony proved
     * @return ?AuthFlowOutcome The taken-address rollback of a lost landing, or null when the holder answers
     * @throws EmptyValueException When the display name the new account is created with is empty
     * @throws InvalidFormatException When the proven address is not a valid identifier
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When the account, identity, project bookkeeping, or reservation write fails
     */
    private function signInProvenAddress(ActingSession $acting, string $email): ?AuthFlowOutcome
    {
        $userId = Hilos::$db->identities->findAccountIdByEmail($email);
        if ($userId !== null) {
            Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email)?->markVerified();
            $this->releaseOwnHoldOn($email, $acting->sessionToken);

            // The mark goes on before the sign-in, so the identity and the news reach the
            // browser in one frame (HIL-422) - an order the session holder keeps, since it
            // is the one that owns both. Answering the letter is the whole of what this
            // ending asks for, so "you are in" is all there is to say about it.
            $this->library->grantSession(
                $acting,
                $userId,
                SessionAck::SIGNED_IN,
                AuthFlowOutcome::moveTo(AuthFlowStep::DONE, AuthFlowIntent::LOGIN),
            );

            return null;
        }

        return $this->landRegistration($acting, $email, $this->displayNameFromEmail($email));
    }

    /**
     * Drops this browser's hold when it names the address that just resolved.
     *
     * The hold is the session's since HIL-608, so it is dropped only when it is about
     * THIS address: a browser whose unfinished registration is on another address is
     * still running it, and clearing that would take away a code screen nobody left.
     *
     * @param string $identifier Normalized identifier that just resolved to an account
     * @param string $sessionToken Session cookie token of the browser that proved it
     * @throws HilosException When the reservation lookup or delete fails
     */
    private function releaseOwnHoldOn(string $identifier, string $sessionToken): void
    {
        $reservations = new RegistrationReservationService();
        if ($reservations->findActiveForSession($sessionToken)?->identifier === $identifier) {
            $reservations->release($sessionToken);
        }
    }
}
