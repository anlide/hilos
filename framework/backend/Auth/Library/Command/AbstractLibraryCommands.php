<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Hilos;
use Hilos\HilosException;

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
     * The sign-in, the marks on the sockets and the word to the losers are all one frame to
     * the session holder ({@see AbstractUsersLibraryAgent::announceRegistrationLanded()}):
     * the library has no session to raise and no parked socket to reach.
     *
     * @param ActingSession $acting Browser the proof arrived on
     * @param string $identifier Normalized identifier the proof just settled (lowercased email or E.164)
     * @param string $displayName Name the new account is created with
     * @return ?AuthFlowOutcome The taken-address rollback to answer with, or null when the holder answers
     * @throws EmptyValueException When the display name is empty
     * @throws InvalidFormatException When the proven identifier is neither an address nor a number
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When the account, identity, project bookkeeping, or reservation write fails
     */
    protected function landRegistration(
        ActingSession $acting,
        string $identifier,
        string $displayName,
    ): ?AuthFlowOutcome {
        Database::transactionStart();
        try {
            $userId = $this->library->createUser($displayName);
            $losers = new RegistrationReservationService()
                ->confirmProvenAddress($acting->sessionToken, $identifier, $userId);
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
     * Ends the landing transaction after a failure, whichever failure it was.
     *
     * Every way out of {@see landRegistration()} that is not a commit goes through here,
     * because the connection under it belongs to the WORKER and outlives the action: the
     * router answers the caller and keeps the worker running, so a transaction left open
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
