<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Closure;
use Hilos\Auth\Code\CodeSendTicket;
use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\Verification\CodeDeliveryAvailability;
use Hilos\Auth\Verification\VerificationSendOutcome;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Random\RandomException;

/**
 * Base of the seven groups the sign-in commands are split into (HIL-622).
 *
 * The groups exist because the commands do: a password, a mailed link, a phone code, a
 * passkey and a provider are five ceremonies that share an ending and nothing else, and
 * keeping each one whole is what lets a project read the one it cares about. They are
 * classes rather than methods of the agent for the plainest of reasons - together they
 * are some fifteen hundred lines, and an agent that also held them would be a file nobody
 * opens on purpose.
 *
 * What a group is given is its library ({@see AbstractUsersLibraryAgent}) and nothing
 * else. The library is where the project's seams are - what a user of this project is
 * made of, which methods it offers, which channels it can reach - and where the frames to
 * the session holder go out from. A group therefore never touches a session, and cannot:
 * the writes that raise one are not on anything it holds.
 *
 * A group is reached only through a project that declares {@see HilosFeature::AUTH} and
 * registers a library agent; the chat demo is the first one that does.
 */
abstract class AbstractLibraryCommands
{
    /**
     * @param AbstractUsersLibraryAgent $library Library whose seams and frames this group runs on
     */
    public function __construct(protected readonly AbstractUsersLibraryAgent $library)
    {
    }

    /**
     * Resolves the browser behind one accept key, refusing a socket that has no session.
     *
     * The prologue every command shares. A command is dispatched with an accept key and
     * needs the session behind it, because that is what a hold, a wait and a grant are
     * written under; a socket without one has nothing a sign-in could be about, and
     * saying so here keeps every command below free of the check.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @return ActingSession The socket, its browser session, and whoever is signed in on it
     * @throws ItemNotFoundForUpdateException When no live connection carries the key, or it has no session
     */
    protected function acting(string $acceptKey): ActingSession
    {
        $connection = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey);
        if ($connection?->sessionToken === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        return new ActingSession($acceptKey, $connection->sessionToken, $connection->userId);
    }

    /**
     * Opens the send-progress line of this browser and names the send it will follow (HIL-826).
     *
     * The first state of the line is reported over the same frame every later one travels on,
     * rather than drawn into the action reply: one path for all five states and one place that
     * fans out. The cost is that the line arrives a tick after the code screen does, which is
     * the shape the ticket asks for - two sources of truth on the first frame is what it avoids.
     *
     * The ticket goes back to the caller because the caller is what hands it to the transport;
     * from there it comes back untouched with every step, and matching it against the line is
     * the whole of the resend race.
     *
     * The session is named by the HASH of its token, which is what the line is keyed by and
     * what a sender is allowed to know: whoever reports a step never holds the token itself.
     *
     * @param ActingSession $acting Browser that is about to be sent a code
     * @param string $channel Channel the code travels over - `email` or a code channel key
     * @return string Ticket of this send, to be handed to whoever carries it
     * @throws RandomException When the platform CSPRNG cannot mint the ticket
     * @throws InvalidArgumentException When the step frame cannot be named or queued
     */
    protected function openCodeSendLine(ActingSession $acting, string $channel): string
    {
        $ticket = CodeSendTicket::mint();

        $this->library->sendToAgent(
            HilosSignalConstants::HILOS_CODE_SEND_STEP,
            CodeSendStepSignalData::queued(
                $ticket,
                StateProtectedModeRuntime::hashSessionToken($acting->sessionToken),
                $channel,
            ),
        );

        return $ticket;
    }

    /**
     * Says what the send gate did to a code the line is already following (HIL-826).
     *
     * The line opens when the code is ORDERED, which is honest - at that moment it is queued -
     * but a send the gate refuses never reaches a transport, so nothing would ever move it
     * again. A person who pressed resend one second early would sit on the code screen reading
     * "queued" about a letter nobody wrote.
     *
     * The mapping is the phone path's, and for its reasons ({@see AuthCodeAgent}): a cooldown
     * hold means an earlier code went out and IS what the screen is waiting for, so the line
     * says `sent` - unless this installation writes its letters instead of mailing them, where
     * it closes with `not_sent` (HIL-1003); a cap refusal means nothing is travelling at all, so
     * it stops promising. A send that really went out is left alone - the transport carrying it
     * reports the rest.
     *
     * @param string $ticket Ticket the line is following
     * @param string $channel Channel the line belongs to
     * @param VerificationSendOutcome $outcome What the send gate answered
     * @throws InvalidArgumentException When the step frame cannot be named or queued
     */
    protected function closeRefusedCodeSendLine(string $ticket, string $channel, VerificationSendOutcome $outcome): void
    {
        if ($outcome->sent) {
            return;
        }

        $this->library->sendToAgent(
            HilosSignalConstants::HILOS_CODE_SEND_STEP,
            CodeSendStepSignalData::step($ticket, $this->refusedLineState($channel, $outcome)),
        );
    }

    /**
     * Which state a refused send leaves on the line.
     *
     * A cap refusal means nothing is travelling and no waiting fixes it, so the line stops
     * promising. A cooldown hold means an earlier code went out and IS what the screen is
     * waiting for - unless this installation writes its letters instead of mailing them, in
     * which case the earlier code went nowhere either, and the line says so with the state
     * HIL-827 added for exactly that installation.
     *
     * Only the mail channel is asked: the expert answers for mail, and a phone code held by
     * the cooldown really did go out ({@see AuthCodeAgent::lineStateFor()}).
     *
     * @param string $channel Channel the line belongs to ({@see HilosCodeSendAttempt::CHANNEL_EMAIL} or a code channel key)
     * @param VerificationSendOutcome $outcome What the send gate answered
     * @return string State the line is closed with
     */
    private function refusedLineState(string $channel, VerificationSendOutcome $outcome): string
    {
        if ($outcome->capReached) {
            return HilosCodeSendAttempt::STATE_FAILED;
        }

        if ($channel === HilosCodeSendAttempt::CHANNEL_EMAIL && new CodeDeliveryAvailability()->mailIsKeptAtHome()) {
            return HilosCodeSendAttempt::STATE_NOT_SENT;
        }

        return HilosCodeSendAttempt::STATE_SENT;
    }

    /**
     * Resolves the browser behind one accept key and refuses it while it is anonymous.
     *
     * For the commands that add to an account rather than open one - linking a provider,
     * enrolling a passkey. They are in the library's AUTH_ACTIONS as well, so the
     * dispatcher has already turned an anonymous caller away; this is the same fact read
     * off the row the command is about to act on, which is what makes the user id below
     * an `int` instead of something every line has to re-check.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @return ActingSession The socket, its browser session, and the user signed in on it
     * @throws ItemNotFoundForUpdateException When no live connection carries the key, it has no session, or it is anonymous
     */
    protected function actingUser(string $acceptKey): ActingSession
    {
        $acting = $this->acting($acceptKey);
        if ($acting->userId === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        return $acting;
    }

    /**
     * Whether an email already belongs to an account, by any method.
     *
     * The question the identifier-first surface asks before reserving: not "is there a
     * password identity" but "is this address somebody's". An account created through
     * OAuth carries the address as a verified identity of another type (HIL-405), and
     * offering to register it would either fail at the identity write or quietly build a
     * second account for the same person; the surface sends them to sign-in instead, and
     * the profile owns adding a password to an account that has none (HIL-406).
     *
     * The pair it used to spell out is the framework's one definition of a taken address
     * now ({@see Identities::findAccountIdByEmail()}, HIL-608), and this reads it
     * rather than repeating it: while there were several spellings of the question they
     * disagreed on an address carrying an unverified password identity, and the
     * disagreement built a second account for the same person.
     *
     * @param string $email Lowercased submitted email
     * @return bool True when an account already holds the address
     * @throws HilosException When the identity lookup fails
     */
    protected function emailBelongsToAccount(string $email): bool
    {
        return Hilos::$db->identities->findAccountIdByEmail($email) !== null;
    }

    /**
     * Whether an identifier of either kind already belongs to an account (HIL-1104).
     *
     * The same question as {@see emailBelongsToAccount()}, asked by the first door that meets
     * both kinds before any code: the passkey door takes whatever stands in the field. A number
     * belongs to an account when it is somebody's phone identity - the answer the identifier
     * lookup gives for a number, and the only one a phone registration could collide with.
     *
     * @param string $kind Classification of the identifier (see IdentifierDetection::KIND_*)
     * @param string $normalized Identifier in its canonical form (lowercased address or E.164 number)
     * @return bool True when an account already holds the identifier
     * @throws HilosException When the identity lookup fails
     */
    protected function identifierBelongsToAccount(string $kind, string $normalized): bool
    {
        if ($kind === IdentifierDetection::KIND_PHONE) {
            return Hilos::$db->identities->findByIdentity(IdentityType::SMS, $normalized) !== null;
        }

        return $this->emailBelongsToAccount($normalized);
    }

    /**
     * Derives the default display name from an email address.
     *
     * Uses the local part (everything before the first `@`); the name is not an
     * identifier and stays editable later in Profile.
     *
     * @param string $email Lowercased account email
     * @return string Display name (email local part, or the whole string when no `@`)
     */
    protected function displayNameFromEmail(string $email): string
    {
        $atPosition = strpos($email, '@');

        return $atPosition === false ? $email : substr($email, 0, $atPosition);
    }

    /**
     * Mints the account a proven identifier earns, lands its hold and tells everyone else.
     *
     * The one ending the three proofs share - a typed registration code, a clicked link,
     * a phone code - because what a proof buys is the same whichever arrived: an account,
     * the identity this browser's hold earns, whatever the project writes about a new
     * member, a signed-in session, and a word to every other browser that was on the
     * identifier. Three copies of that order would eventually mean three different kinds
     * of member (HIL-608), which is why it is on the base of all three groups.
     *
     * The mint and the landing go in ONE transaction. They are two writes about a person
     * who does not exist yet, and the race this opens - several browsers proving one
     * address - is settled by the identity's unique key: the loser must leave no user
     * behind, since nothing has been announced yet and an orphan account nobody can sign
     * into would be the only trace of it. The loser is answered exactly as a taken address
     * is, which is what it now is.
     *
     * WHAT the account signs in with is the caller's to say (HIL-825). A password
     * registration ends on a password screen and hands the plaintext through to be hashed
     * into the identity here, at the moment the account comes into being; a link and a
     * phone code carry none, and the hold's own type names the secret-less identity they
     * earn. A caller whose hold does not name it says so itself: the way past the password
     * screen (HIL-1008) leaves a hold taken for a password and earns the mailed link.
     *
     * An ending may owe the account more than its address, and then says so with
     * `$withAccount` (HIL-1104): the passkey ending writes the key the account signs in with.
     * It is called with the new user's id INSIDE the transaction, after the address landed
     * and before the commit, because an account whose key failed to store is an account
     * nobody can sign into - it has to go the way the loser of the race goes, leaving nothing.
     * What it throws rolls the landing back and reaches the caller as it was thrown.
     *
     * The sign-in, the marks on the sockets and the word to the losers are all one frame to
     * the session holder ({@see AbstractUsersLibraryAgent::announceRegistrationLanded()}):
     * the library has no session to raise and no parked socket to reach.
     *
     * @param ActingSession $acting Browser the proof arrived on
     * @param string $identifier Normalized identifier the proof just settled (lowercased email or E.164)
     * @param string $displayName Name the new account is created with
     * @param ?string $plainPassword Password the account signs in with, or null for a way in that carries none
     * @param ?string $landAs Identity a secret-less landing earns (see IdentityType), or null to take the hold's own type
     * @param ?Closure(int): void $withAccount What else the new account is written with, given its user id, or null for nothing
     * @return ?AuthFlowOutcome The taken-address rollback to answer with, or null when the holder answers
     * @throws EmptyValueException When the display name is empty
     * @throws InvalidFormatException When the proven identifier is neither an address nor a number
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When the account, identity, project bookkeeping, or reservation write fails,
     *     or whatever `$withAccount` throws
     */
    protected function landRegistration(
        ActingSession $acting,
        string $identifier,
        string $displayName,
        ?string $plainPassword = null,
        ?string $landAs = null,
        ?Closure $withAccount = null,
    ): ?AuthFlowOutcome {
        Database::transactionStart();
        try {
            $userId = $this->library->createUser($displayName);
            $losers = new RegistrationReservationService()
                ->confirmProvenAddress($acting->sessionToken, $identifier, $userId, $plainPassword, $landAs);
            if ($withAccount !== null) {
                $withAccount($userId);
            }
            Database::transactionCommit();
        } catch (DuplicateValueException) {
            $this->endFailedLanding();

            return AuthFlowOutcome::rejectTo(
                AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
                AuthFlowStep::IDENTIFIER,
                AuthFlowIntent::LOGIN,
                AuthMessages::IDENTIFIER_TAKEN,
            );
        } catch (HilosException $failure) {
            $this->endFailedLanding();

            throw $failure;
        }

        $this->library->afterUserCreated($userId, $identifier);
        $this->library->announceRegistrationLanded(
            $acting,
            $identifier,
            $userId,
            $losers,
            AuthFlowOutcome::moveTo(AuthFlowStep::DONE, AuthFlowIntent::REGISTER),
        );

        return null;
    }

    /**
     * Mints an account that holds no address at all, with the way in the caller writes (HIL-1104).
     *
     * The ending of the passkey door's road without a code. Nobody proved the typed address,
     * and the owner decided on 26.09.2026 that it is NOT stored: an unproven address is read by
     * nothing - letters, recovery and step-up all go to a confirmed one only - and since an
     * identity's (type, identifier) pair is unique, storing it would let anybody take a stranger's
     * address, whose owner would later hear "this address is taken" when registering it. The
     * typed address only labeled the key in the device prompt and named the account.
     *
     * So this is {@see landRegistration()} without the address. The mint and the way in go in ONE
     * transaction for the same reason the landing's do: an account without its key is an account
     * nobody will ever sign into. No other browser is told anything - the address was not taken,
     * so whoever else is registering it is still registering it - and only this browser's own
     * hold, if it had one on an address it never proved, is dropped: it names a registration this
     * browser is no longer running.
     *
     * The sign-in is a grant rather than a landing: there is no address for the holder to settle
     * and no loser to tell. It carries the same mark and the same answer the landing carries, so
     * the surface ends on the same "account created" screen.
     *
     * @param ActingSession $acting Browser that asked for the account
     * @param string $identifier Normalized identifier that was typed, handed to the project's new-member bookkeeping
     * @param string $displayName Name the new account is created with
     * @param Closure(int): void $withAccount The way in the new account is written with, given its user id
     * @throws EmptyValueException When the display name is empty
     * @throws InvalidArgumentException When the grant frame cannot be named or queued
     * @throws HilosException When the account, project bookkeeping, or reservation write fails, or whatever
     *     `$withAccount` throws
     */
    protected function landAccountWithoutAddress(
        ActingSession $acting,
        string $identifier,
        string $displayName,
        Closure $withAccount,
    ): void {
        Database::transactionStart();
        try {
            $userId = $this->library->createUser($displayName);
            $withAccount($userId);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            $this->endFailedLanding();

            throw $failure;
        }

        $this->library->afterUserCreated($userId, $identifier);
        new RegistrationReservationService()->release($acting->sessionToken);
        $this->library->grantSession(
            $acting,
            $userId,
            SessionAck::REGISTERED,
            AuthFlowOutcome::moveTo(AuthFlowStep::DONE, AuthFlowIntent::REGISTER),
        );
    }

    /**
     * Ends the landing transaction after a failure, whichever failure it was.
     *
     * Every way out of {@see landRegistration()} and {@see landAccountWithoutAddress()} that is
     * not a commit goes through here, because the connection under it belongs to the WORKER and
     * outlives the action: the router answers the caller and keeps the worker running, so a
     * transaction left open
     * would quietly take in every later write that worker makes and would in the end be
     * committed by an unrelated BEGIN - together with the orphan account that has no
     * identity, which is the very thing this transaction exists to prevent.
     *
     * A rollback that fails on its own is dropped rather than reported: the caller is
     * owed the failure that ended the landing, and a second one about the cleanup would
     * take its place.
     */
    private function endFailedLanding(): void
    {
        try {
            Database::transactionRollback();
        } catch (HilosException) {
            // Reporting the cleanup would replace the failure the caller is owed
        }
    }
}
