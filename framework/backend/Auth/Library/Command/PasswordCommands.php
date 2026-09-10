<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\DTO\AbandonRegistrationActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationActionDTO;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RequestRegisterConfirmActionDTO;
use Hilos\Auth\PasswordPolicy;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Random\RandomException;

/**
 * The password door: signing in with one, and the registration that mints one (HIL-622).
 *
 * One group because they are one ceremony seen from two sides - the address either has a
 * password identity behind it or is about to get one - and because both of them read the
 * same "is this address somebody's" before deciding anything.
 *
 * Registration is THREE submits and only the last of them writes the account (HIL-825). The
 * first reserves the address for this browser and asks for a code; the code proves the
 * address and leaves that proof on the hold; the password saved afterwards is what mints
 * the user, its verified identity and the credential all at once. So nothing half-made
 * exists at any point between them - no account without a way in, and no stored secret for
 * an account that was never created.
 */
final class PasswordCommands extends AbstractLibraryCommands
{
    /**
     * Signs a session in with an email and a password.
     *
     * Three sentences instead of one generic refusal (HIL-414): no account, an account
     * with no password, a wrong password. The generic one was there to hide which
     * addresses have accounts, and the live lookup in front of this form answers exactly
     * that question now - so vagueness withheld nothing and only left the person guessing
     * which half of what they typed was wrong.
     *
     * The address names the PERSON and the secret is theirs, not the address's (HIL-692).
     * It used to ask for "the password of this address", which meant a second address -
     * one that arrived by a merge, say - showed a password field that always answered
     * "this account has no password", with nothing the person could type to change that.
     * The three sentences are unchanged; what moved is what each is computed from.
     *
     * The hash is re-computed when the cost parameters have moved on, which is the one
     * write a successful login does here. Everything else about becoming that user - the
     * rotated token, the re-pointed sockets, the answer to this action - belongs to the
     * session holder and leaves in the grant frame.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param LoginActionDTO $dto Parsed login payload (email, password)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the address, the account or the password refuses
     * @throws InvalidArgumentException When the grant frame cannot be named or queued
     * @throws HilosException When the identity lookup or the rehash fails
     */
    public function login(string $acceptKey, LoginActionDTO $dto): void
    {
        $acting = $this->acting($acceptKey);

        $email = strtolower($dto->email);
        $userId = $email !== ''
            ? Hilos::$db->identities->findAccountIdByEmail($email)
            : null;

        if ($userId === null) {
            throw new ValidationException(AuthMessages::UNKNOWN_EMAIL);
        }

        $identity = Hilos::$db->identities->findPasswordByUser($userId);
        if ($identity === null) {
            throw new ValidationException(AuthMessages::NO_PASSWORD);
        }

        if (!$identity->verifyPassword($dto->password)) {
            throw new ValidationException(AuthMessages::WRONG_PASSWORD);
        }

        $identity->rehashPasswordIfNeeded($dto->password);

        $this->library->grantSession($acting, $userId);
    }

    /**
     * Reserves an email for a new account and sends the code that will create it.
     *
     * The submit registers nobody (HIL-415), and since HIL-825 it does not even carry a
     * password: the address is all it asks for. It validates, then holds the address for
     * THIS BROWSER for a TTL and asks for one confirmation code. What the surface is told
     * back is where to go next:
     * - the address is free: the code step, with the moment a re-send is allowed. That
     *   holds whether or not somebody else is registering the same address - this browser
     *   gets a hold of its own (HIL-608) and the first to SAVE A PASSWORD wins the account
     *   (HIL-825), while the send gate decides whether a second letter is owed;
     * - the address belongs to an account: not an error the person has to read and
     *   retype, but a move to the identifier step under the sign-in intent. Registration
     *   legitimately reveals a taken address (that is a login concern, not one here).
     *
     * The gate's verdict is passed on rather than hidden. A cooldown answers the honest
     * seconds - it is the same letter, already in the inbox - and only the window cap
     * refuses out loud. Silence here is what stranded the second person to start on a
     * code screen with nothing coming.
     *
     * The connection is parked as a waiter before returning, so it is reachable by the
     * converge broadcast whoever finishes the registration first.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param RegisterActionDTO $dto Parsed register payload (email)
     * @return AuthFlowOutcome Where the surface goes next
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws EmptyValueException When the email is empty
     * @throws InvalidFormatException When the email is not a valid address
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When identity lookup, the reservation, or the runtime write fails
     */
    public function register(string $acceptKey, RegisterActionDTO $dto): AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = strtolower($dto->email);
        if ($email === '') {
            throw new EmptyValueException('Email is required');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidFormatException('Enter a valid email address');
        }

        if ($this->emailBelongsToAccount($email)) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN,
                AuthMessages::IDENTIFIER_TAKEN,
            );
        }

        $ticket = $this->openCodeSendLine($acting, StateHilosCodeSendAttempt::CHANNEL_EMAIL);
        $outcome = new RegistrationReservationService()->reserve(
            IdentityType::PASSWORD,
            $acting->sessionToken,
            $email,
            $ticket,
        );
        $this->closeRefusedCodeSendLine($ticket, $outcome);

        $this->parkRegistrationWait($acting, $email);

        if ($outcome->capReached) {
            return AuthFlowOutcome::refuse(AuthFlowOutcome::CODE_SEND_CAP_REACHED, AuthMessages::SEND_CAP);
        }

        return AuthFlowOutcome::moveTo(
            AuthFlowStep::CODE,
            AuthFlowIntent::REGISTER,
            $outcome->resendAt(),
            new VerificationService()->activeExpiresAt(VerificationType::REGISTER_CONFIRM, $email),
        );
    }

    /**
     * Re-sends the confirmation code of a pending registration.
     *
     * The resend button on the code screen (HIL-415). It is not a second registration:
     * THIS BROWSER's hold on the address is what decides whether there is anything to
     * re-send (HIL-608), and when it is gone the surface is sent to the screen that says
     * the code is dead and offers a new one (HIL-828), under a code of its own rather than
     * told "no". It is not sent BACK to the address field: the way out of a code that ran
     * out is one button, and the address is still the one the person came with. A resend
     * inside the cooldown sends nothing and answers with the seconds still to wait - the
     * countdown the screen draws.
     *
     * The hold is pushed out only when a code actually went out, so a button mashed
     * inside the cooldown moves nothing. What stops the patient caller - the one that
     * presses once per cooldown, forever, keeping the address held and its owner mailed -
     * is the window cap inside the send gate (HIL-421). It refuses out loud here rather
     * than counting down, because no wait short enough to draw would bring the button back.
     *
     * Any browser holding the address may press it: the cooldown belongs to the address,
     * not to the session, so a second registering browser presses into the same countdown
     * - and pressing re-parks the presser so a converge reaches it either way.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param RequestRegisterConfirmActionDTO $dto Parsed resend payload (email)
     * @return AuthFlowOutcome Where the surface goes next
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws RandomException When the platform CSPRNG cannot produce a code
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When the reservation, verification, or runtime write fails
     */
    public function requestRegisterConfirm(string $acceptKey, RequestRegisterConfirmActionDTO $dto): AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = strtolower($dto->email);
        $reservations = new RegistrationReservationService();
        if ($reservations->findActiveForSession($acting->sessionToken)?->identifier !== $email) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
                AuthFlowStep::CODE_EXPIRED,
                AuthFlowIntent::REGISTER,
                AuthMessages::RESERVATION_EXPIRED,
            );
        }

        $ticket = $this->openCodeSendLine($acting, StateHilosCodeSendAttempt::CHANNEL_EMAIL);
        $outcome = new VerificationService()->issue(
            VerificationType::REGISTER_CONFIRM,
            $email,
            null,
            $ticket,
        );
        $this->closeRefusedCodeSendLine($ticket, $outcome);
        if ($outcome->sent) {
            $reservations->extendTo($acting->sessionToken);
        }

        // Parked before the cap is answered, unlike the expired hold above: a capped
        // resend leaves the person ON the code screen, so the converge still has to
        // reach them when somebody else redeems the address.
        $this->parkRegistrationWait($acting, $email);

        if ($outcome->capReached) {
            return AuthFlowOutcome::refuse(AuthFlowOutcome::CODE_SEND_CAP_REACHED, AuthMessages::SEND_CAP);
        }

        return AuthFlowOutcome::moveTo(
            AuthFlowStep::CODE,
            AuthFlowIntent::REGISTER,
            $outcome->resendAt(),
            new VerificationService()->activeExpiresAt(VerificationType::REGISTER_CONFIRM, $email),
        );
    }

    /**
     * Confirms a reserved registration: proves the address and opens the password step.
     *
     * The moment the address becomes provably this person's (HIL-415, HIL-825). It does
     * NOT create the account: between the code and a chosen password there would stand a
     * real account with a proven address and no way to sign into it. So what the accepted
     * code produces is a mark on the hold and a move to the password screen, and the user,
     * the verified identity and the credential are all written together when that screen
     * is submitted ({@see completeRegistration()}).
     *
     * The code is still spent, single-use as ever. It can be, precisely because the proof
     * moved off it and onto the hold, where it outlives a reload and a closed tab; the
     * hold is pushed out to a full TTL in the same breath, since from here it holds an
     * address while somebody invents a password rather than a letter in transit.
     *
     * Four answers, and the difference between the middle two is the whole point of the
     * design: a wrong code is an inline error that leaves the person on the code screen
     * to try again, while a hold that ran out is not their mistake at all and moves the
     * surface to the screen that says so and offers a new code (HIL-828), with a reason
     * of its own.
     *
     * The hold that has to be there is THIS BROWSER's, on THIS address (HIL-608). A code
     * typed where no such hold stands proves nothing, whoever is registering the address
     * elsewhere: the letter went to the inbox, so the person reading it can register the
     * address in their own browser, but they cannot finish somebody else's attempt.
     *
     * The fourth is the address having become somebody's while it was held. The hold
     * keeps a SECOND registration off it, not an account arriving by another road - an
     * OAuth sign-in mints one from a verified email of its own type (HIL-405), and that
     * identity does not collide with the password one written later. So the question the
     * submit asked is asked again, and answered the same way: not an error to retype, but
     * a move to sign-in. Without it a person would be sent to choose a password for an
     * address that is already somebody's.
     *
     * The OTHER browsers racing this address are not touched here, and that is the whole
     * of what moved with the password: the address belongs to nobody yet, so there is
     * nothing to take from them. The race is settled at the save instead, by whoever
     * finishes first ({@see AbstractLibraryCommands::landRegistration()}).
     *
     * Nothing is answered from here: the proof is a fact about the BROWSER, so the session
     * holder moves this browser's other tabs onto the password step and answers the
     * submitting one LAST.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param ConfirmRegisterActionDTO $dto Parsed confirm payload (email, code)
     * @return ?AuthFlowOutcome Where the surface goes next, or null when the session holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the code is wrong, expired, or exhausted
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When the reservation write or an identity lookup fails
     */
    public function confirmRegister(string $acceptKey, ConfirmRegisterActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = strtolower($dto->email);
        $reservations = new RegistrationReservationService();
        if ($reservations->findActiveForSession($acting->sessionToken)?->identifier !== $email) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
                AuthFlowStep::CODE_EXPIRED,
                AuthFlowIntent::REGISTER,
                AuthMessages::RESERVATION_EXPIRED,
            );
        }

        // Asked here too, not only at the submit: the hold blocks another registration
        // on the address, not an account that arrived by another road while it stood.
        if ($this->emailBelongsToAccount($email)) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN,
                AuthMessages::IDENTIFIER_TAKEN,
            );
        }

        if (!$reservations->verifyCode($email, $dto->code)) {
            throw new ValidationException(AuthMessages::INVALID_CODE);
        }

        if (!$reservations->markProven($acting->sessionToken, $email)) {
            // The hold was checked at the top of this method and went away since - swept,
            // or evicted by another socket of this browser submitting a different address.
            // The truthful answer is the one an expired hold gets.
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
                AuthFlowStep::CODE_EXPIRED,
                AuthFlowIntent::REGISTER,
                AuthMessages::RESERVATION_EXPIRED,
            );
        }

        $this->library->announceRegistrationProven(
            $acting,
            $email,
            AuthFlowOutcome::moveTo(AuthFlowStep::SET_PASSWORD, AuthFlowIntent::REGISTER),
        );

        return null;
    }

    /**
     * Saves the first password of a proved registration, which is what creates the account.
     *
     * The submit the whole leaf exists for (HIL-825). The user, its VERIFIED password
     * identity and whatever the project writes about a new member are all written here, in
     * one transaction, from a plaintext that has never been stored anywhere: until this
     * moment a registration is a held address and a mark saying its code came back.
     *
     * The address comes off the proved hold and never off the payload, exactly as in
     * {@see RecoveryCommands::completePasswordReset()} - a password screen that could name
     * an address would be a way to take one somebody else proved.
     *
     * Four answers, in the order they are asked. No proved hold - it ran out, it was
     * evicted, or this browser never had one - is not the person's mistake and rolls the
     * surface back to the address field. A password under the minimum is an ordinary
     * error on the field; the check lives here now because before this leaf there was no
     * password to check at the submit. The address having become somebody's while the
     * password was being chosen is a move to sign-in, not an error to retype. Everything
     * else lands.
     *
     * This is also where the race between browsers is settled, and it moved here with the
     * account (HIL-608 settled it at the code). Several browsers may prove one address -
     * the letter reaches the inbox, not the browser - and the one that saves a password
     * first takes it; the rest are answered exactly as a taken address is, by
     * {@see AbstractLibraryCommands::landRegistration()} catching the duplicate identity.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param CompleteRegistrationActionDTO $dto Parsed complete payload (password)
     * @return ?AuthFlowOutcome Where the surface goes next, or null when the session holder answers
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the password is too short
     * @throws EmptyValueException When the display name the new account is created with is empty
     * @throws InvalidFormatException When the proved address is not a valid identifier
     * @throws InvalidArgumentException When the landing frame cannot be named or queued
     * @throws HilosException When the account, identity, project bookkeeping, or reservation write fails
     */
    public function completeRegistration(string $acceptKey, CompleteRegistrationActionDTO $dto): ?AuthFlowOutcome
    {
        $acting = $this->acting($acceptKey);

        $email = new RegistrationReservationService()->findProvenForSession($acting->sessionToken)?->identifier;
        if ($email === null) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::REGISTER,
                AuthMessages::RESERVATION_EXPIRED,
            );
        }

        if (strlen($dto->password) < PasswordPolicy::MIN_LENGTH) {
            throw new ValidationException('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters');
        }

        // Asked once more, for the reason it is asked at the code: the hold keeps a second
        // REGISTRATION off the address, not an account that arrived by another road while
        // somebody was choosing a password.
        if ($this->emailBelongsToAccount($email)) {
            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN,
                AuthMessages::IDENTIFIER_TAKEN,
            );
        }

        return $this->landRegistration(
            $acting,
            $email,
            $this->displayNameFromEmail($email),
            $dto->password,
        );
    }

    /**
     * Ends the registration this session was waiting on ("not that address?").
     *
     * The way back from a code screen (HIL-486). It forgets the wait - the durable memory
     * and the parked sockets alike - so this session's tabs go to the identifier field
     * together, and a reconnect is answered with no step at all.
     *
     * It deliberately does NOT free the hold, and the reason changed with the key
     * (HIL-608). It used to be that the hold belonged to the address and other sessions
     * might be waiting on it; the hold is this browser's own now, and keeping it is what
     * the way BACK is built on - a person who returns to the same address is put back on
     * their own code screen by the lookup alone, spending no second letter. Releasing it
     * would delete the `pending` answer that screen stands on. The hold runs out on its
     * own, and the sweep tells whoever is left.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param AbandonRegistrationActionDTO $dto Parsed abandon payload (no fields)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When the durable release fails
     */
    public function abandonRegistration(string $acceptKey, AbandonRegistrationActionDTO $dto): void
    {
        $acting = $this->acting($acceptKey);

        $this->library->announceRegistrationAbandoned(
            $acting,
            AuthFlowOutcome::moveTo(AuthFlowStep::IDENTIFIER, AuthFlowIntent::REGISTER),
        );
    }

    /**
     * Parks this browser on an address it is registering, in the runtime and in the session row.
     *
     * Both halves of one wait, which is why they are one call: the parked socket is what a
     * converge reaches while the tab is open, and the row on the session is what answers a
     * reconnect with the code screen it left. Two callers do this identically and a third
     * would have.
     *
     * The runtime half takes two steps since HIL-685, and the pair is one park. This
     * library may bring a waiter row into being and take it away; it may not edit one, and
     * a browser that submits a second address already HAS a row. So the missing row is
     * added here and the frame beside it asks the session holder - the collection's one
     * full truth source - to make an existing one say the new address.
     *
     * @param ActingSession $acting Browser being parked
     * @param string $identifier Normalized address it is registering
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When the runtime park or the durable hold fails
     */
    private function parkRegistrationWait(ActingSession $acting, string $identifier): void
    {
        Hilos::$rt->hilosRegistrationWaiters->actions->park($acting->acceptKey, $identifier, $acting->sessionToken);
        $this->library->announceRegistrationWaitMoved($acting, $identifier);
        Hilos::$db->sessions->findByToken($acting->sessionToken)?->actions->holdPendingRegistration($identifier);
    }
}
