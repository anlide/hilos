<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Auth\Library\Command\RecoveryCommands;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationAbandonedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Recovery\PasswordRecoveryService;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Registration\RegistrationReservationSweeper;
use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\Auth\Session\DTO\DismissSessionAckActionDTO;
use Hilos\Auth\Session\DTO\DismissSessionToastActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateStartActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Auth\Session\DTO\LogoutActionDTO;
use Hilos\Auth\Session\DTO\RaiseSessionToastSignalData;
use Hilos\Auth\Session\DTO\SessionCarryOverDoneSignalData;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\SessionToastExpiredActionDTO;
use Hilos\Auth\Session\DTO\SessionToastReadingActionDTO;
use Hilos\Auth\Session\DTO\SessionToastsSignalData;
use Hilos\Auth\Session\Exception\SessionNotOnConnectionException;
use Hilos\Auth\Session\Exception\SessionTokenExhaustedException;
use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\Session\SessionCarrier;
use Hilos\Auth\Session\SessionRebindConstants;
use Hilos\Auth\Session\SessionRotationTicket;
use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Auth\Session\SessionToken;
use Hilos\Auth\Throttle\ThrottleGate;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\NotImplementedException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\View\Item\Session;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Actions\Collection\RecoveryWaitersActions;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\AccountMergeSummary;
use Hilos\Users\AdminCommandConstants;
use Hilos\Users\DTO\AccountMergeResultSignalData;
use Hilos\Users\DTO\AccountMergeSignalData;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Random\RandomException;
use Throwable;

/**
 * The sessions library: the one owner of the session set and of the handshake that opens it.
 *
 * An entity library in the sense of docs/agents/architecture/entity-libraries.md, and the
 * second one in code (HIL-710). What it owns is a SET - the session rows, the token
 * rotations, and the sign-in surfaces parked on a confirmation code - together with the
 * ceremonies that write them: resolving a handshake cookie to a session, raising a session
 * to a user, reverting it to anonymous, and settling every tab that was waiting.
 *
 * It used to be a TRAIT mixed into whichever project agent happened to accept handshakes,
 * and that was the defect the split closes. Sessions belonged to a project agent only
 * historically: until HIL-622 the sign-in page lived in its worker. Once the sign-in
 * commands left for {@see AbstractUsersLibraryAgent}, the sessions stayed behind, and the
 * seam between the two became the place defects were born. A separate library rather than a
 * corner of the users one, because one entity is one library: sessions are hot - every
 * handshake touches them - and users are cold, so the two have to be placed independently.
 *
 * WHAT IT DOES NOT OWN: the connection rows. Who is on the wire is the truth of the project
 * that accepted the socket, its row carries project fields, and the close that ends it stays
 * there too. So everything this library concludes about a session is SAID to that project in
 * one frame ({@see HilosSignalConstants::HILOS_SESSION_STATE}), and everything a project
 * wants written on a session is asked for in the one going back
 * ({@see HilosSignalConstants::HILOS_SESSION_REBIND}). The tab is answered by the project,
 * which is what makes the order in that answer hold: it writes the connection row and sends
 * the identity from one queue.
 *
 * It is ABSTRACT for one reason: {@see CliCommands::ADMIN_CREATE} ends in a user row, and
 * the framework does not know the shape of a project's users table. The project supplies
 * that step through {@see ensureAdminUser()} and nothing else - a project with a login of
 * its own mounts the command nowhere and inherits the refusing default.
 *
 * A session is anonymous (user id null) until {@see authenticateSession()} binds a user;
 * {@see deauthenticateSession()} is the symmetric downgrade that keeps the session row and
 * token alive. The session-expiry drop (HIL-398) is enforced in
 * {@see resolveHandshakeSession()}: a cookie that resolves to an authenticated but expired
 * session is downgraded to anonymous before it is handed back, so a stale cookie can never
 * resume an authenticated identity.
 */
abstract class AbstractSessionsLibraryAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SESSIONS_LIBRARY;

    /**
     * The frames this library is addressed by: seven from the users library, two from the
     * project holding the sockets, and one from anybody with something to say to a browser.
     *
     * Routing takes the destination from whoever declares a name here, so this list IS the
     * move: the frames the users library has always sent to "the holder" now arrive at an
     * agent of the framework's own, and nothing about their senders changed (HIL-710).
     * {@see HilosSignalConstants::HILOS_SESSION_STATE} is deliberately absent - it is the
     * frame this library SENDS, and the project declares it. So is
     * {@see HilosSignalConstants::HILOS_ACCOUNT_MERGE_RESULT}, the answer to the ninth.
     *
     * The tenth has no fixed sender at all (HIL-768): a toast addressed to a session may be
     * raised by any agent that finished something a person is waiting on, and it arrives here
     * because the stack it lands on is the session's.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_AUTH_SESSION_GRANT => AuthSessionGrantSignalData::class,
        HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED => AuthRegistrationLandedSignalData::class,
        HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED => AuthRecoveryGrantedSignalData::class,
        HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED => AuthPasswordChangedSignalData::class,
        HilosSignalConstants::HILOS_AUTH_REGISTRATION_ABANDONED => AuthRegistrationAbandonedSignalData::class,
        HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED => AuthRegistrationWaitMovedSignalData::class,
        HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED => AuthRecoveryWaitMovedSignalData::class,
        HilosSignalConstants::HILOS_SESSION_REBIND => SessionRebindSignalData::class,
        HilosSignalConstants::HILOS_ACCOUNT_MERGE => AccountMergeSignalData::class,
        HilosSignalConstants::HILOS_SESSION_TOAST_RAISE => RaiseSessionToastSignalData::class,
    ];

    /**
     * The page-independent controls a browser has over its own session, by wire name.
     *
     * Every one of them resolves its session from the ACTING connection, so a client can
     * only ever end, dismiss or vacate its own; the one payload that names somebody says whom
     * to become and still cannot say who is asking. They are the library's rather than a
     * project's because what they write is a session (HIL-710, HIL-729).
     *
     * The impersonation pair judges a project field on the way in - the flag that says
     * "administrator" - and that used to be the reason it stayed in the project. It is not a
     * reason to own the action, only to ask: the question goes back over
     * {@see self::assertImpersonationAllowed()} while the write stays here, which is the
     * same split the grant pair above already runs on.
     *
     * The last three are the tabs of one session answering about the toasts the server raised
     * for it (HIL-768): closed, counted down, being read. They sit here for the plainest
     * version of the same reason - the stack they answer about is stored on the session, and
     * an answer given in one tab has to reach the others.
     */
    public const array AGENT_ACTIONS = [
        HilosSignalConstants::HILOS_LOGOUT => LogoutActionDTO::class,
        HilosSignalConstants::HILOS_DISMISS_SESSION_ACK => DismissSessionAckActionDTO::class,
        HilosSignalConstants::HILOS_IMPERSONATE_START => ImpersonateStartActionDTO::class,
        HilosSignalConstants::HILOS_IMPERSONATE_STOP => ImpersonateStopActionDTO::class,
        HilosSignalConstants::HILOS_TOAST_DISMISS => DismissSessionToastActionDTO::class,
        HilosSignalConstants::HILOS_TOAST_EXPIRED => SessionToastExpiredActionDTO::class,
        HilosSignalConstants::HILOS_TOAST_READING => SessionToastReadingActionDTO::class,
    ];

    /**
     * The six operator paths that end in a SESSION being told who it is now, mounted here
     * because sessions are this library's.
     *
     * {@see CliCommands::ADMIN_CREATE} (HIL-609) names a browser by its cookie and binds it.
     * The grant pair (HIL-553, moved here by HIL-729) names a user id and flips a flag - and
     * it belongs beside its sibling rather than on the index agent, because the flag changes
     * what the person's open tabs may show, and only this library knows which sockets those
     * are and how to make it say so ({@see self::publishSessionState()}). While the pair
     * stood on the index agent every project wrote that announcement itself, and the three
     * copies said three different things: chat's carried the server clock and the pending
     * ack, the other two carried neither.
     *
     * The impersonation pair (HIL-166, moved here by HIL-729) is the third path and the
     * only one that does not write a flag: it writes the session itself, naming another
     * person as who it acts for, and the administrator it may go back to on the row behind
     * that. It shares its whole body with the browser control of the same name - one core,
     * two ways in - which is why the wire name is one on both sides.
     *
     * The merge ({@see CliCommands::ACCOUNT_MERGE}, HIL-378, moved here by HIL-729) is the
     * last, and it reaches a session by consequence rather than by aim: what it asks for is
     * that two accounts become one, and the loser's open sessions have to be signed out
     * before that is true. It is also the one command with a second way in - an admin table
     * submits it as a page action and the page forwards it on
     * {@see HilosSignalConstants::HILOS_ACCOUNT_MERGE} - so the two entrances share one core
     * and differ only in whom the answer goes to.
     *
     * Every project that registers the library answers all six, and one that wires no
     * seam answers a REFUSAL ({@see ensureAdminUser()}, {@see applyAdminGrant()},
     * {@see assertImpersonationAllowed()}, {@see assertMergeable()}) rather than nothing at
     * all. That is the honest outcome for an operator who typed it into the wrong
     * installation: before the move the name was carried by whichever agent chose to, so a
     * project that did not left the command socket silent - which reads as a hang, not as a
     * no.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::ADMIN_CREATE,
        CliCommands::ADMIN_GRANT,
        CliCommands::ADMIN_REVOKE,
        CliCommands::IMPERSONATE_START,
        CliCommands::IMPERSONATE_STOP,
        CliCommands::ACCOUNT_MERGE,
    ];

    /** @var int Times a rotation re-mints a token another session already holds before giving up */
    private const int TOKEN_MINT_ATTEMPTS = 3;

    /**
     * @var int Byte length of a session toast's name; the name is these bytes in hex
     *
     * Not a secret - a browser only ever answers about the cards its own session's row holds -
     * but drawn on the rotation ticket's form for want of a reason to invent a second one.
     */
    private const int TOAST_KEY_RANDOM_BYTES = 16;

    /** Name of the cron rule that clears abandoned registrations off session rows. */
    private const string PENDING_REGISTRATION_SWEEP_RULE = 'hilos_sweep_pending_registrations';

    /** Name of the cron rule that frees the registration holds whose deadline passed. */
    private const string RESERVATION_SWEEP_RULE = 'hilos_sweep_registration_reservations';

    /**
     * How often the expired registration holds are swept.
     *
     * Not an env value and not a project's to schedule, unlike the sweep below it: an
     * expired hold leaves a person looking at a code screen for a code that can no longer
     * confirm anything, so the sweep answers somebody and cannot be switched off. Every
     * minute is what the chat demo scheduled by hand before this became the library's.
     */
    private const string RESERVATION_SWEEP_CRON = '* * * * *';

    /** @var string What a browser is told when its connection carries no session to act on */
    private const string SESSION_NOT_ON_CONNECTION_MESSAGE
        = 'The session behind this tab could not be found; reload the page and try again';

    /** @var ?CronRule Schedule of the abandoned-registration sweep, or null when it is switched off */
    private ?CronRule $pendingRegistrationSweepRule = null;

    /** @var ?CronRule Schedule of the expired-hold sweep, or null when this project has no registration */
    private ?CronRule $reservationSweepRule = null;

    /**
     * Claims the session set and arms the two sweeps that keep it honest.
     *
     * The session set, the rotations and the toast stacks are claimed OUTRIGHT: a session
     * belongs to this library whole. Nothing may write a framework collection until somebody
     * says who owns it, so a project that never registers this agent simply has no sessions
     * rather than sessions changing in a process no one else hears. The stacks (HIL-768) are
     * claimed unconditionally like the rotations and for the same reason: what a browser is
     * being shown is decided by its session, which every project carrying this agent has.
     *
     * The two WAIT collections are claimed only where they exist. They are mounted by
     * {@see AuthFeature::mount()} and by nothing else, so in a project with no sign-in
     * surface there is no collection to own - and a library that claimed one anyway would
     * go on to read it every tick and raise on every pass. The users library stands beside
     * this one on both as a declared add/remove co-owner (HIL-685) rather than as a second
     * full owner, and it is only ever registered where the feature is.
     *
     * The identity table is a borrowed claim, and the comment on it says whose it is. It is
     * here because the account merge is here: {@see Identities::rePointToUser()} moves the
     * loser's identities onto the survivor and demotes or drops the password among them, so
     * the claim covers editing and removal and nothing else. That the right had to be said
     * out loud is what HIL-716 changed - it used to be asked only of the four eagerly loaded
     * collections, and this one is lazy.
     *
     * A subclass that overrides this MUST call up: the claims are what the whole library
     * stands on.
     *
     * @throws EnvException When the sweep schedule key is missing, outside the catalog, or of the wrong type
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(HilosDbContext::sessions);
        $this->registerRtTruthSource(StateHilosSessionRotation::RT_COLLECTION);
        $this->registerRtTruthSource(StateHilosSessionToastStack::RT_COLLECTION);
        if ($this->hasSignInSurface()) {
            $this->registerRtTruthSource(StateRegistrationWaiter::RT_COLLECTION);
            $this->registerRtTruthSource(StateRecoveryWaiter::RT_COLLECTION);
            // TODO(HIL-630): borrowed claim - the users library owns the reservation table.
            // The hold sweep is armed here because the expiry it announces rolls back a WAIT,
            // which is this library's row; the sweep itself belongs with the table. Claimed
            // under the same condition that arms the sweep, so a project with no sign-in
            // surface does not claim a table it never writes.
            $this->registerDbTruthSource(HilosDbContext::registrationReservations);
        }
        // TODO(HIL-630): borrowed claim - the users library owns the identity table. The
        // merge runs here because it is the sessions that have to be signed out with it,
        // and the claim is named as narrowly as its write: rows that already exist are
        // edited or taken away, never minted. Unconditional because the writer is a method
        // of this class, not a project seam - a project that wires the merge inherits it.
        $this->registerDbTruthSource(
            HilosDbContext::identities,
            operations: [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        );

        $this->armPendingRegistrationSweep();
        $this->armReservationSweep();

        $this->carryOverDeferredSessions();
    }

    /**
     * Re-creates the logins a restore photographed before it replaced the database (HIL-771).
     *
     * The last thing the library does on its way up, and the reason the queue exists at all: the
     * restore runs with this agent stopped by the freeze, so it cannot ask - it leaves the picture
     * in {@see DeferredSessionCarryoverQueue} and this is where the picture is used. After the
     * claim above, because applying one WRITES the rows this library owns.
     *
     * The queue is empty in ordinary life: only a restore ever fills it, and only on the branch
     * where the swap succeeded. Contained like everything else about a finished restore - a
     * library that cannot re-create a login must still come up, or the node that just came back
     * has no sessions at all rather than the ones it could not carry.
     *
     * A pass that found something to do is reported to this node's master, which may be holding
     * the "the freeze lifted, reload" frame back until it hears this ({@see reportCarriedOverSessions()}).
     */
    private function carryOverDeferredSessions(): void
    {
        // Named before the try because the catch below counts it: the drain itself can fail, and
        // an unset variable there would turn a reported loss into a second failure.
        $snapshot = [];

        try {
            $snapshot = DeferredSessionCarryoverQueue::drain();
            if ($snapshot === []) {
                return;
            }

            $result = SessionCarrier::carryOver($snapshot);
        } catch (Throwable $e) {
            $this->logAgentError('Deferred session carry-over failed: ' . $e->getMessage());
            // Reported all the same, with nothing carried: what the master is holding the lift for
            // is whether anything more is coming, and after this catch the answer is no. Silence
            // here would cost the browsers the whole timeout and tell the operator, in a second
            // log line, what the one above already said.
            $this->reportCarriedOverSessions(0, count($snapshot));

            return;
        }

        $this->logAgentInfo("Carried over {$result->carried} restored session(s), dropped {$result->dropped}");
        $this->reportCarriedOverSessions($result->carried, $result->dropped);
    }

    /**
     * Tells this node's master that the logins a restore left here have been dealt with.
     *
     * The answer to the debt the restore reported when it queued them (HIL-771): until it arrives,
     * the master holds back the frame that tells every browser the freeze has lifted, because that
     * frame means "reload" and a reload arriving before these rows exist signs their owners out.
     * Sent only when there WAS a queue to empty - an ordinary start drains nothing and reports
     * nothing, and a master owed nothing is not waiting.
     *
     * The naming throw is contained here rather than carried out of {@see onStart()}: the name is
     * a constant, so it cannot happen, and a library refusing to come up over an unsendable report
     * would cost the node its sessions to save its browsers one reload.
     *
     * @param int $carried Logins written into the restored database
     * @param int $dropped Logins that will not survive the restore
     */
    private function reportCarriedOverSessions(int $carried, int $dropped): void
    {
        try {
            Hilos::$sr?->queueSignal(
                signalSource: $this->getAgentSignalSource(),
                signalType: new SignalType(SignalTypeConstants::SESSION_CARRY_OVER_DONE),
                signalName: new SignalName(SignalTypeConstants::SESSION_CARRY_OVER_DONE),
                signalData: new SessionCarryOverDoneSignalData($carried, $dropped),
            );
        } catch (InvalidArgumentException $e) {
            $this->logAgentError('Carried-over sessions could not be reported: ' . $e->getMessage());
        }
    }

    /**
     * Whether this project draws a sign-in surface, and therefore has the rows to sweep.
     *
     * The one question three of the sweeps below have to ask, named once so they ask it the
     * same way. Sessions are NOT a feature - the tasks and simple-poll demos carry them with
     * no login at all - but the browsers parked on a confirmation code are: the collections
     * holding them are mounted by {@see AuthFeature::mount()}, and the reservation table the
     * hold sweep reads is declared by the same feature. Asked of the registry rather than of
     * an artifact, because the registry is what a project actually declares.
     *
     * @return bool True when {@see HilosFeature::AUTH} is declared by this project
     */
    private function hasSignInSurface(): bool
    {
        return Hilos::hasFeature(HilosFeature::AUTH);
    }

    /**
     * The library holds nothing across a stop: its state is the database and the collections
     * above, which outlive the process that owns them.
     */
    public function onStop(): void
    {
    }

    /**
     * Reclaims what nobody came back for, and rolls back the browsers waiting on it.
     *
     * Two of the four walks are over in-memory collections that hold one row per login in
     * the last thirty seconds and one per sign-in surface parked on a confirmation code, so
     * they are measured in microseconds and skipped outright while nobody is registering or
     * recovering - which is almost always. The two that read the database are behind cron
     * rules of their own instead of running every pass.
     *
     * Everything but the rotations and the toast stacks is behind the sign-in surface, because
     * everything but those two is a row the surface makes. A project without one reaches this method just
     * as often - it has sessions like any other - and the walk it would take there is over a
     * collection that was never mounted, which raises rather than answering empty. That is
     * the runtime collections doing their job: an unmounted collection is not an empty one.
     *
     * @throws HilosException On database or runtime failure
     * @throws EnvException When the verification TTL key is missing, outside the catalog, or of the wrong type
     * @throws InvalidArgumentException When a converge signal of the rollback cannot be named
     */
    public function onTick(): void
    {
        $this->sweepSessionRotations();
        $this->sweepSessionToasts();
        if (!$this->hasSignInSurface()) {
            return;
        }

        $this->sweepPendingRegistrations();
        $this->sweepRegistrationReservations();
        $this->sweepRegistrationWaiters();
        $this->sweepRecoveryWaiters();
    }

    /**
     * Arms the schedule of the abandoned-registration sweep (HIL-612).
     *
     * An empty expression arms nothing, which is the whole switch a project needs - one that
     * never sets the key sweeps nothing and pays no tick for it.
     *
     * @throws EnvException When the schedule key is missing, outside the catalog, or of the wrong type
     */
    private function armPendingRegistrationSweep(): void
    {
        $expression = Hilos::$env?->string(EnvConstants::HILOS_PENDING_REGISTRATION_SWEEP_CRON);
        if ($expression === null || trim($expression) === '') {
            return;
        }

        $this->pendingRegistrationSweepRule = new CronRule(self::PENDING_REGISTRATION_SWEEP_RULE, $expression);
    }

    /**
     * Arms the schedule of the expired-hold sweep (HIL-415), where there are holds to sweep.
     *
     * Asked of the feature registry rather than of an artifact, which is the one way the
     * question may be asked: a project without {@see HilosFeature::AUTH} has no reservation
     * table, and a sweep armed there would fail every minute over rows that were never meant
     * to exist. It is the first framework gate to read the registry, and the reason the
     * registry was built.
     */
    private function armReservationSweep(): void
    {
        if (!$this->hasSignInSurface()) {
            return;
        }

        $rule = new CronRule(self::RESERVATION_SWEEP_RULE, self::RESERVATION_SWEEP_CRON);
        // Never run rather than just run, which is what a fresh rule claims by default. That
        // default guards a BOOTING DAEMON from firing every rule it holds at once; this is
        // one rule in one agent, and the holds that ran out while nobody held the collection
        // are exactly the ones nobody answered - the browsers waiting on them have been
        // waiting longest, and asking them to wait out the rest of the minute is backwards.
        $rule->lastRun = 0.0;
        $this->reservationSweepRule = $rule;
    }

    /**
     * Frees the registration holds whose deadline passed, and rolls back who was waiting.
     *
     * The one sweep that answers somebody: an expired hold means the browser that made it is
     * waiting for a code that can no longer confirm anything, so each freed hold rolls back
     * its own session the moment its row goes. Its own, and not the address: since HIL-608
     * another browser may be registering the same address with a hold of its own, and that
     * one is still good.
     *
     * @throws HilosException On database or runtime failure
     * @throws InvalidArgumentException When a converge signal of the rollback cannot be named
     */
    private function sweepRegistrationReservations(): void
    {
        if ($this->reservationSweepRule?->shouldRun() !== true) {
            return;
        }

        foreach (new RegistrationReservationSweeper()->sweep() as $freed) {
            $this->rollBackRegistrationWaiters(
                $freed[ObjectRegistrationReservation::sessionToken],
                $freed[ObjectRegistrationReservation::identifier],
            );
        }
    }

    /**
     * Clears the registrations nobody came back to finish (HIL-612).
     *
     * Called from the owning agent's `onTick()`; the rule inside decides whether this
     * tick is the one. Unlike the event-driven releases - a code that came back, a
     * "not that address?", an expiring hold - nothing here answers a person: this is
     * what closes the case where the person simply left. Without it a browser returning
     * days later would be served the code screen of a code that expired long ago.
     *
     * The age of the WAIT is the whole criterion, and the reservation table is
     * deliberately not consulted: a project with no registration has no such table and
     * must still be swept, and the guard is exact anyway - a resend restamps the wait on
     * the same path that extends the hold.
     *
     * @throws HilosException On database or runtime failure
     * @throws EnvException When the verification TTL key is missing, outside the catalog, or of the wrong type
     */
    private function sweepPendingRegistrations(): void
    {
        if ($this->pendingRegistrationSweepRule?->shouldRun() !== true) {
            return;
        }

        $released = Hilos::$db?->sessions->actions->sweepStalePendingRegistrations(
            Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_TTL_SEC),
        );
        if ($released !== null && $released > 0) {
            $this->logAgentInfo("Pending registration sweep: cleared {$released} abandoned registration(s)");
        }
    }

    /**
     * Drops rotations whose ticket is past its moment.
     *
     * Called from the owning agent's `onTick()`. Expired rows are already refused on the
     * handshake, so this reclaims memory rather than closing a hole: without it, every
     * login whose browser never came back to trade its ticket - a tab closed in between -
     * would stay in the collection for the life of the process.
     *
     * @throws HilosException On runtime failure
     */
    private function sweepSessionRotations(): void
    {
        Hilos::$rt?->hilosSessionRotations->actions->forgetExpired();
    }

    /**
     * Re-decides every session's toast stack once a tick, and tells the tabs when one changed.
     *
     * The third of the three entrances to the removal rule, and the only one nobody asks for.
     * The other two are a tab speaking - a countdown finished, a cursor left - and both leave
     * a case they cannot close: the tab that was HOLDING the stack simply closes. Nothing
     * arrives to say so, its hold would veto for the life of the process, and a card burned
     * down in the other window would hang on screen under nobody's eyes. Here the hold stops
     * counting because it is intersected with the sockets that are actually there.
     *
     * The same pass takes away the row of a session with no live socket left. A toast lives
     * only while somebody may be looking at it, and the next tab to open is a person arriving
     * after the fact rather than the person who was told.
     *
     * The live sockets are grouped ONCE for the whole pass rather than asked for per row: the
     * question is answered by hashing the token of every live connection, and doing that per
     * row would repeat the whole walk for each session being shown something.
     *
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the toast frame cannot be named
     */
    private function sweepSessionToasts(): void
    {
        $stacks = Hilos::$rt?->hilosSessionToastStacks;
        if ($stacks === null || count($stacks) === 0) {
            return;
        }

        $sessionTokenHashes = [];
        foreach ($stacks as $sessionTokenHash => $stack) {
            $sessionTokenHashes[] = $sessionTokenHash;
        }

        $liveByHash = $this->liveAcceptKeysBySessionTokenHash();
        foreach ($sessionTokenHashes as $sessionTokenHash) {
            $liveAcceptKeys = $liveByHash[$sessionTokenHash] ?? [];
            if (!$stacks->actions->settle($sessionTokenHash, $liveAcceptKeys)) {
                continue;
            }
            if ($liveAcceptKeys === []) {
                // The row went because the session has no socket left: there is nobody to tell.
                continue;
            }

            $this->publishSessionToasts($sessionTokenHash);
        }
    }

    /**
     * Groups the live connections by the hash of the session token they carry.
     *
     * The lookup the toast stacks need and the session rows do not: a stack is addressed by the
     * HASH of a token, because whoever raised it never held the token itself
     * ({@see AbstractAgent::resolveInitiatorSessionTokenHash()}), and a hash cannot be turned
     * back into the token {@see self::sessionConnectionKeys()} asks by. So the walk goes the
     * other way - every live connection's token is hashed and the keys collected under it.
     *
     * A project with no session-stage connections answers empty, which is the same
     * nothing-to-do as a project with no sockets at all.
     *
     * @return array<string, list<string>> Session token hash => accept keys of its live connections
     */
    private function liveAcceptKeysBySessionTokenHash(): array
    {
        $connections = Hilos::$rt?->sessionConnectionsSource();
        if ($connections === null) {
            return [];
        }

        $byHash = [];
        foreach ($connections as $acceptKey => $connection) {
            $sessionToken = $connection->sessionToken;
            if ($sessionToken === null || $sessionToken === '') {
                continue;
            }

            $byHash[StateProtectedModeRuntime::hashSessionToken($sessionToken)][] = $acceptKey;
        }

        return $byHash;
    }

    /**
     * Sends one session's whole toast stack to every tab of it.
     *
     * Addressed by hash through {@see AbstractAgent::sendToSession()}, so the master matches it
     * against the connections it holds and this library needs no roster of sockets to write the
     * address with. The frame carries the LIST rather than the change: a reconnect, a second
     * tab and an ordinary removal are then one sentence, and an empty list is the legal frame
     * that takes the last card away.
     *
     * Every handshake gets one, INCLUDING the empty one, and that is not a waste to optimize
     * away. A session whose last socket dropped loses its row to the tick; the tab that comes
     * back is then owed nothing, and it is still showing the card it had - with its countdown
     * already reported, so nothing would ever take it off. Silence and "you are owed nothing"
     * look the same from here and mean opposite things to the browser.
     *
     * @param string $sessionTokenHash Hash of the session cookie token being told
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    private function publishSessionToasts(string $sessionTokenHash): void
    {
        $stacks = Hilos::$rt?->hilosSessionToastStacks;
        if ($stacks === null) {
            return;
        }

        $this->sendToSession(
            HilosSignalConstants::HILOS_SESSION_TOASTS,
            $sessionTokenHash,
            SessionToastsSignalData::fromStack($stacks[$sessionTokenHash]),
        );
    }


    /**
     * Opens a socket's session and tells the project what that socket now is.
     *
     * The handshake is addressed HERE and nowhere else since HIL-710: it is the one moment
     * a session is resolved or created, and routing it to the library is what saves a hop
     * on every page load. What the library cannot do is register the connection row - who
     * is on the wire is the project's truth - so the whole of its answer is the state frame
     * below, and the project registers the row, sends its own identity response and
     * releases the page subscribe the browser is holding.
     *
     * The parked wait is written here rather than in the project because it is the
     * library's own row (HIL-486): a socket that opens into a session with an unfinished
     * registration joins the converge broadcast without having submitted anything itself.
     *
     * The inherited ack rides the frame instead of being written on a row that does not
     * exist yet (HIL-423). Only a handshake that spent a rotation ticket carries one: the
     * socket that replaces the one a login rotated away is the same browser mid-flow, not a
     * reload, and it inherits what its predecessor had not shown yet.
     *
     * The toast stack rides beside the frame rather than on it (HIL-768), because it is a
     * sentence to a different audience: the state frame is read by the project holding the
     * socket, and the stack is read by the browser. A tab opened after a card was raised is a
     * legitimate reader of it and starts its own countdown from the full time - it has only
     * now come into view.
     *
     * A socket with no ticket is owed whatever its SESSION still owes (HIL-649). The other
     * tabs a login drops carry no ticket - only the initiator trades one - so until now they
     * came back on the new cookie owing nothing, and the sentence the flow had earned was
     * lost one frame before anyone could read it. The ticket is asked FIRST and the session
     * second because the initiator's replacement arrives when its session owes nothing yet:
     * its own row died with the rotation and no sibling was ever marked, so only the ticket
     * can answer for it.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws InvalidFormatException When the session token is not a 32-character lowercase hex string
     * @throws DuplicateValueException When a concurrent create already claimed a new token
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws HilosException On database or runtime failure
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        // The daemon resolved the session token on the 101 (the client's cookie or a freshly
        // issued one) and carried it on the handshake DTO. Validate inside the
        // ValidationException family so the worker dispatcher contains a bad token instead
        // of crashing.
        $sessionToken = $data->sessionToken;
        SessionToken::ensureValid($sessionToken);

        $session = $this->resolveHandshakeSession($sessionToken);
        $this->parkPendingAuthStep($data->acceptKey, $session);

        $this->publishSessionState(new SessionStateSignalData(
            sessionToken: $session->token,
            userId: $session->userId,
            acceptKeys: [$data->acceptKey],
            pendingAck: $data->inheritedAck ?? $this->sessionPendingAck($session->token),
            pendingAuthStep: $this->pendingAuthStepFor($session),
        ));
        $this->publishSessionToasts(StateProtectedModeRuntime::hashSessionToken($session->token));
    }

    /**
     * Sends one frame of session state to the project holding the sockets.
     *
     * The library's only way to reach a browser, and the reason it needs no hooks: what it
     * knows is the session, what the project knows is the person and the socket, and this
     * is the sentence between them (HIL-710). A frame that answers a tracked action carries
     * the answer with it and the dispatcher is told to keep quiet - the ack has to leave
     * behind the identity it announces, and from now on it leaves from the other process.
     *
     * @param SessionStateSignalData $state What the session is now, and whom to answer
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    private function publishSessionState(SessionStateSignalData $state): void
    {
        $this->sendToAgent(HilosSignalConstants::HILOS_SESSION_STATE, $state);
        if ($state->requestId !== null) {
            $this->deferActionReply();
        }
    }

    /**
     * Reads the ack the live sockets of one session are carrying.
     *
     * Asked of the sockets because that is where the mark lives, and answered with the
     * first one found because the mark is written per SESSION: everything that raises or
     * clears one walks every socket of the session in one pass. They diverge on the ordinary
     * path and not by accident (HIL-649): a login marks the initiator alone and drops the
     * session's other tabs, which reconnect owing nothing until this very read hands them
     * what the session still owes. So the question is what the SESSION owes, and any live
     * socket of it is an equally good witness.
     *
     * @param string $sessionToken Session cookie token whose sockets are read
     * @return ?string Ack the session owes (a {@see SessionAck} value), or null for none
     */
    private function sessionPendingAck(string $sessionToken): ?string
    {
        foreach ($this->sessionConnectionKeys($sessionToken) as $acceptKey) {
            $ack = $this->connectionPendingAck($acceptKey);
            if ($ack !== null) {
                return $ack;
            }
        }

        return null;
    }

    /**
     * Describes the auth step a session stands on and has not finished (HIL-486, HIL-648).
     *
     * The step the surface comes back to, served from the server rather than kept in
     * the tab: a reload, a second tab and another device all ask the same question at
     * their handshake and get the same answer. A session standing on neither flow
     * answers null - the flow completed or ran out, and the person belongs on the
     * identifier field, not on a code screen for a code that can no longer be
     * confirmed.
     *
     * RECOVERY IS ASKED FIRST, and the reason is what each record survives (HIL-648).
     * A recovery row exists only while the flow is open in a live tab, so its presence
     * is evidence the person is on it now; a registration wait is durable and may have
     * been abandoned a week ago. A session cannot honestly stand on both, and when the
     * two records disagree the fresher one is the truthful answer.
     *
     * TWO records have to agree, and they answer different questions (HIL-608). The WAIT
     * on the session row says this browser is sitting on a code screen: it is written by
     * the flows that put it there and dropped by "not that address?", so a hold with no
     * wait beside it is a registration nobody is watching a code field for - a mailed
     * sign-in link, or an attempt its owner walked away from - and resuming either onto a
     * code screen would ask for a code that was never issued. The HOLD says WHICH
     * registration and until when, and the address is taken off it rather than off the
     * wait, because a hold made on another address after the wait was written would
     * otherwise be described with the wait's stale identifier.
     *
     * The channel is asked for a number only: a code sent to an address goes by mail
     * whatever else is registered, and naming a channel there would be inventing a
     * choice nobody made.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return ?array{identifier: string, kind: string, intent: string, step: string, channel: ?string, expiresAt: int}
     *     Step, or null when there is none
     * @throws HilosException When the reservation, runtime or verification query fails
     */
    private function pendingAuthStepFor(?Session $session): ?array
    {
        if ($session === null) {
            return null;
        }

        $recovery = $this->recoveryStepFor($session);
        if ($recovery !== null) {
            return $recovery;
        }

        if ($session->pendingRegistrationIdentifier === null) {
            return null;
        }

        $reservation = new RegistrationReservationService()->findActiveForSession($session->token);
        if ($reservation === null) {
            return null;
        }

        $identifier = $reservation->identifier;
        try {
            $kind = IdentifierDetector::kindOf($identifier);
        } catch (InvalidFormatException $e) {
            // A hold is only ever written for an identifier this same classifier
            // accepted, so a row that no longer classifies is corrupt rather than
            // unusual. The session is told it has no step - which is the truthful
            // answer about a registration nobody can finish - and the row is named in
            // the log rather than taken out on the handshake, which every socket of
            // every session depends on.
            $this->logAgentWarning('Registration hold carries an unusable identifier: ' . $e->getMessage());

            return null;
        }

        return [
            HandshakeResponseSignalData::identifier => $identifier,
            HandshakeResponseSignalData::kind => $kind,
            HandshakeResponseSignalData::intent => AuthFlowIntent::REGISTER,
            HandshakeResponseSignalData::step => AuthFlowStep::CODE,
            HandshakeResponseSignalData::channel => $kind === IdentifierDetection::KIND_PHONE
                ? new VerificationService()->activeChannel(VerificationType::SMS_LOGIN, $identifier)
                : null,
            HandshakeResponseSignalData::expiresAt => TimeHelper::sqlToMs($reservation->expiresAt),
        ];
    }

    /**
     * Describes the password recovery a session stands on, or null when it stands on none (HIL-648).
     *
     * The recovery half of {@see pendingAuthStepFor()}, and the reason a tab opened after
     * the code was accepted lands on the password screen instead of typing the code again:
     * the grant belongs to the SESSION, so a connection that never submitted anything still
     * inherits the step its siblings reached.
     *
     * The row is chosen by the SAME rule the saving step finds its address with
     * ({@see RecoveryCommands::grantedRecoveryIdentifier()}): the first row of the session
     * carrying a grant names the password step, and with no granted row the first parked
     * row names the code step. One rule and not two - a screen and a submit that picked
     * different rows would offer "choose a new password" and then refuse the save for an
     * address the person never saw.
     *
     * Answered only while the code behind it is still alive, because the grant is worth
     * exactly what the code is: a dead code makes the password step a screen that refuses
     * on submit, and the honest place for that person is the identifier field. The kind is
     * email and the channel is null without asking, since recovery accepts nothing else.
     *
     * The sign-in surface is asked about FIRST, and this is the one gate that has to be
     * here: the waiter collection is mounted by {@see AuthFeature::mount()}, so a project
     * that carries sessions without a login (tasks, simple-poll) holds no such collection,
     * and reading it would fail every handshake of a project that has no recovery to
     * describe. Registration's half needs no such gate - it asks the session row, which
     * every project has.
     *
     * @param Session $session Session to describe
     * @return ?array{identifier: string, kind: string, intent: string, step: string, channel: ?string, expiresAt: int}
     *     Step, or null when the session stands on no live recovery
     * @throws HilosException When the runtime read or a verification query fails
     */
    private function recoveryStepFor(Session $session): ?array
    {
        if (!$this->hasSignInSurface()) {
            return null;
        }

        $granted = null;
        $firstParked = null;
        foreach (Hilos::$rt?->hilosRecoveryWaiters->forSessionToken($session->token) ?? [] as $parked) {
            $firstParked ??= $parked;
            if ($parked->codeAccepted) {
                $granted = $parked;
                break;
            }
        }

        $waiter = $granted ?? $firstParked;
        if ($waiter === null) {
            return null;
        }

        $identifier = $waiter->identifier;
        if (!new PasswordRecoveryService()->hasLiveCode($identifier)) {
            return null;
        }

        $expiresAt = new VerificationService()->activeExpiresAt(VerificationType::PASSWORD_RESET, $identifier);
        if ($expiresAt === null) {
            // The live-code check and this read are two queries, and a challenge that
            // died between them leaves nothing to count down to. Telling the session it
            // stands on no step is the same answer a dead code gets one line above.
            return null;
        }

        return [
            HandshakeResponseSignalData::identifier => $identifier,
            HandshakeResponseSignalData::kind => IdentifierDetection::KIND_EMAIL,
            HandshakeResponseSignalData::intent => AuthFlowIntent::RECOVERY,
            HandshakeResponseSignalData::step => $granted === null
                ? AuthFlowStep::CODE
                : AuthFlowStep::SET_PASSWORD,
            HandshakeResponseSignalData::channel => null,
            HandshakeResponseSignalData::expiresAt => $expiresAt,
        ];
    }

    /**
     * Parks a connection on the auth step its session left unfinished (HIL-486, HIL-648).
     *
     * The runtime waiter list stops being state of its own and becomes a projection
     * of the durable wait: a socket that opens into a session with a wait joins the
     * converge broadcast without having submitted anything itself. That is what makes
     * a second tab react as though the code had been typed in it - which the flow
     * requires, and which no amount of per-connection memory could give it, because
     * the connection asking is new.
     *
     * Recovery is parked for the same reason and by the same existing actions: a fresh
     * tab told which step it stands on would otherwise stand there deaf, and never hear
     * that the password was already changed from another device - converge travels the
     * parked rows. Its grant is copied along with it, because the step the tab was just
     * told is exactly the step its row has to buy; {@see RecoveryWaitersActions::acceptCodeForSession()}
     * is idempotent and grants the address the session already proved.
     *
     * Both flows are parked rather than the one that won the response: which step is
     * SHOWN is a question about now, and which broadcasts a connection belongs to is a
     * question about what it must not miss. Registration's park is unchanged.
     *
     * Called by the project's handshake handler, which is the only place holding both
     * the accept key and the moment: the response builder above is also used for
     * broadcasts to sockets that are already parked or deliberately are not.
     *
     * @param string $acceptKey Accept key of the connection that just handshook
     * @param ?Session $session Session the connection resolved to, or null when it has none
     * @throws HilosException When the runtime read, a verification query, or the runtime write fails
     */
    private function parkPendingAuthStep(string $acceptKey, ?Session $session): void
    {
        if ($session === null) {
            return;
        }

        $recovery = $this->recoveryStepFor($session);
        if ($recovery !== null) {
            $recovered = $recovery[HandshakeResponseSignalData::identifier];
            Hilos::$rt?->hilosRecoveryWaiters->actions->park($acceptKey, $recovered, $session->token);
            if ($recovery[HandshakeResponseSignalData::step] === AuthFlowStep::SET_PASSWORD) {
                Hilos::$rt?->hilosRecoveryWaiters->actions->acceptCodeForSession($session->token, $recovered);
            }
        }

        $identifier = $session->pendingRegistrationIdentifier;
        if ($identifier === null) {
            return;
        }

        Hilos::$rt?->hilosRegistrationWaiters->actions->park($acceptKey, $identifier, $session->token);
    }

    /**
     * Returns the accept keys of the live connections belonging to a session token.
     *
     * Framework-owned since HIL-509: the connection rows stand on a framework base
     * whose session stage carries the token, so the registry is found by type
     * rather than named by the project. A project whose connections do not reach
     * that stage — or which keeps none — has no connections to re-point, and gets
     * the empty list that says exactly that.
     *
     * @param string $sessionToken Session cookie token
     * @return list<string> Accept keys of the token's live connections (empty for an unknown token)
     */
    private function sessionConnectionKeys(string $sessionToken): array
    {
        $connections = Hilos::$rt?->sessionConnectionsSource();
        if ($connections === null) {
            return [];
        }

        return array_keys($connections->findAllBySessionToken($sessionToken));
    }

    /**
     * Returns the success ack one live connection still owes its person (HIL-422).
     *
     * Every handshake response has to be re-addressed with this before it goes out,
     * including the ones that have nothing to do with acks: the payload states the
     * mark rather than amending it, so a response that left the key out of its own
     * ignorance would clear an announcement the person has not read yet. A socket
     * this node does not hold owes nothing, which is also what a project without
     * session-stage connections answers.
     *
     * @param string $acceptKey Connection accept key
     * @return ?string Ack the connection owes (a {@see SessionAck} value), or null for none
     */
    private function connectionPendingAck(string $acceptKey): ?string
    {
        return Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->pendingAck;
    }

    /**
     * Makes one browser session an administrator - the agent half of
     * {@see CliCommands::ADMIN_CREATE} (HIL-609).
     *
     * One path, taken whole every time: resolve the session by its token, take the user it
     * carries or mint one through {@see self::ensureAdminUser()}, then authenticate the
     * session onto that user. The five outcomes an operator can meet - a session with no
     * user, a session carrying a visitor, a session that is already an administrator, a
     * session whose expiry has passed, a token naming no session - fall out of that one path
     * rather than out of five branches, which would be five places to forget the re-point
     * that makes the grant visible.
     *
     * {@see self::authenticateSession()} runs even when the flag was already set: it is what
     * re-points the session's live connections and fans the handshake response out to every
     * tab, so a fresh administrator is shown the way in without a reload. Re-binding the
     * same user changes nothing else, so the repeat costs nothing.
     *
     * Whether a row was minted is read BEFORE the seam is called: once the session is bound
     * nothing tells a mint from a grant, and that is the one thing the operator cannot infer
     * for himself.
     *
     * Any failure - a project that never wired the seam, a token of the wrong shape, a
     * refused write - becomes exactly one error reply, because a CLI parked on the command
     * socket must learn the outcome rather than time out.
     *
     * The session goes through {@see self::resolveHandshakeSession()}, the very door a
     * handshake uses, so an expired session named by an operator is dropped to anonymous by
     * the HIL-398 rule instead of being re-bound and slid forward: an expired access stays
     * expired, and the administrator becomes a NEW user rather than the one the stale cookie
     * still names. The operator learns it happened from
     * {@see AdminCommandConstants::FIELD_EXPIRED}, because the reply otherwise differs only
     * by a user id he has never seen.
     *
     * The plain lookup stays in FRONT of that door and is not redundant: the door mints an
     * anonymous session for a token it does not know, so without the lookup an operator's
     * typo would create both a session and an account.
     *
     * The block flag this command still does not consult, and that remains deliberate: it
     * judges a BROWSER presenting a cookie by itself, and this is an operator naming a
     * session on purpose. It grants nothing extra either - whoever reaches this
     * unauthenticated socket can already {@see CliCommands::ADMIN_GRANT} any user id.
     *
     * @param CommandRequestDTO $data Command request carrying the session cookie token
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    private function handleAdminCreateCommand(CommandRequestDTO $data): void
    {
        // The command socket authenticates nobody, so the payload is whatever was typed at
        // it; an absent token is refused by the shape check on the very next line.
        // external-boundary: an operator's command line, refused a line below
        $sessionToken = (string)($data->payload[AdminCommandConstants::FIELD_SESSION_TOKEN] ?? '');

        try {
            SessionToken::ensureValid($sessionToken);
            $session = Hilos::$db->sessions->findByToken($sessionToken);
            if ($session === null) {
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'No session for that token'));

                return;
            }

            // What the row carried BEFORE the door, which is the only thing that tells an
            // expiry drop from a session that was anonymous all along.
            $userIdBeforeDoor = $session->userId;
            $session = $this->resolveHandshakeSession($sessionToken);

            $created = $session->userId === null;
            // The door unbinds a user for exactly one reason - the expiry - so the two reads
            // around it name it without repeating the TTL comparison that lives inside.
            $expired = $userIdBeforeDoor !== null && $session->userId === null;
            $userId = $this->ensureAdminUser($session->userId);
            $this->authenticateSession($sessionToken, $userId, null);
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            AdminCommandConstants::FIELD_USER_ID => $userId,
            AdminCommandConstants::FIELD_ADMIN => true,
            AdminCommandConstants::FIELD_CREATED => $created,
            AdminCommandConstants::FIELD_EXPIRED => $expired,
        ]));
    }

    /**
     * Makes one user an administrator, minting the row when there is no user yet - the
     * project's half of {@see self::handleAdminCreateCommand()}.
     *
     * A seam with a refusing default rather than an abstract method, the shape
     * {@see self::applyAdminGrant()} uses: {@see self::AGENT_COMMANDS}
     * stands on this class, so every project subclassing it mounts the command whether or
     * not it has anybody to mint - the chat demo, which has a login of its own, is one. An
     * abstract method would make each of them write a body for a command they never expect
     * to be typed; the refusal reaches the operator as the command's error reply instead,
     * which is the honest answer to a command aimed at the wrong installation.
     *
     * One seam rather than two, because the caller's question is one question: make this
     * session's person an administrator. A project that answered "flag" and "mint" apart
     * would own two ways of writing the same flag.
     *
     * The session bind is NOT the implementation's to do - the framework does it around this
     * call. An implementation writes the row and nothing else.
     *
     * @param ?int $userId User the session carries, or null when it carries none
     * @return int Id of the user that is now an administrator
     * @throws NotImplementedException When the project has not wired the minting seam
     * @throws HilosException Whatever the project's implementation raises, an unknown user among it
     */
    protected function ensureAdminUser(?int $userId): int
    {
        throw new NotImplementedException('Admin minting is not wired in this project');
    }

    /**
     * Writes one user's admin flag and tells that user's open tabs - the agent half of
     * {@see CliCommands::ADMIN_GRANT} and {@see CliCommands::ADMIN_REVOKE} (HIL-553).
     *
     * Both wire names land here and the flag comes from the payload rather than from the
     * command name, so the two commands are one handler: grant and revoke differ in nothing
     * but the boolean, and a second copy of the lookup would be a second place to get it
     * wrong.
     *
     * The write itself belongs to the project ({@see self::applyAdminGrant()}), which is
     * also where an unknown user is refused: the framework does not know the collection the
     * project keeps its users in. Any failure from there - an unwired project, an unknown
     * user, a database error - becomes one error reply, because a CLI parked on the command
     * socket must learn the outcome rather than time out.
     *
     * The ANNOUNCEMENT is not the project's, and that is what the move to this library
     * bought (HIL-729): a flag written in silence reaches the browser only on the next
     * reload, and until then a fresh administrator is shown no way in. Saying it out loud
     * needs the person's sockets and the session behind each of them, which is exactly what
     * this library holds - so the flag is followed by one state frame per live session, the
     * same frame every other ending of a session travels on. The project's own handler
     * re-sends the identity from it and re-asks its open pages the access question
     * ({@see PageAccessReassessment}), once per frame, as it already does for a sign-in.
     *
     * @param CommandRequestDTO $data Command request carrying the target user id and admin flag
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     * @throws HilosException On runtime failure while reading the person's live connections
     */
    private function handleAdminGrantCommand(CommandRequestDTO $data): void
    {
        // The command socket authenticates nobody, so the payload is whatever was typed at
        // it; a missing id must not fall through to a user id of 0.
        // external-boundary: an operator's command line, refused a line below
        $userId = $data->payload[AdminCommandConstants::FIELD_USER_ID] ?? null;
        if (!is_int($userId) || $userId <= 0) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Admin grant needs a positive userId',
            ));

            return;
        }

        // external-boundary: an operator's command line; absent reads as a revoke, which is
        // the safe half of the pair to default to
        $admin = (bool)($data->payload[AdminCommandConstants::FIELD_ADMIN] ?? false);

        try {
            $this->applyAdminGrant($userId, $admin);
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->announceAdminGrant($userId);

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            AdminCommandConstants::FIELD_USER_ID => $userId,
            AdminCommandConstants::FIELD_ADMIN => $admin,
        ]));
    }

    /**
     * Restates every live session of one user, so their open tabs learn the flag changed.
     *
     * A restatement rather than a change: the frame carries the session as it is now, and
     * the project handler on the other end re-sends the identity and re-decides the pages
     * from it. Nothing about the session itself moved, which is why no rotation is named and
     * no action is answered - what the frame does carry is the ack the sockets are already
     * holding, because a response that left it out would clear an announcement the person has
     * not read yet (HIL-422).
     *
     * The sessions are found through the LIVE CONNECTIONS rather than through the session
     * table, and the two are not the same question: what has to be told is the tabs that are
     * open, and a session row this node holds no socket for names nobody while still costing
     * the project a page sweep. It is also the node limit the sign-out seam has - a tab held
     * open elsewhere in a cluster learns the flag when its own node reads the row - and it is
     * what lets the grant answer in a worker that has no session table loaded.
     *
     * The registration step is null throughout, and truthfully so: the frame reaches sockets
     * that carry a signed-in person, and signing in is what releases that step.
     *
     * @param int $userId User whose sessions are restated
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws HilosException On runtime failure
     */
    private function announceAdminGrant(int $userId): void
    {
        $connections = Hilos::$rt?->sessionConnectionsSource();
        if ($connections === null) {
            return;
        }

        $keysByToken = [];
        foreach ($connections->findByUser($userId) as $acceptKey => $connection) {
            $sessionToken = $connection->sessionToken;
            if ($sessionToken !== null) {
                $keysByToken[$sessionToken][] = $acceptKey;
            }
        }

        foreach ($keysByToken as $sessionToken => $acceptKeys) {
            $this->publishSessionState(new SessionStateSignalData(
                sessionToken: (string)$sessionToken,
                userId: $userId,
                acceptKeys: $acceptKeys,
                pendingAck: $this->sessionPendingAck((string)$sessionToken),
            ));
        }
    }

    /**
     * Writes the admin flag of one user - the project's half of the grant pair.
     *
     * A seam with a refusing default rather than an abstract method, the shape
     * {@see self::ensureAdminUser()} uses and for the same reason: the mount stands on this
     * class, so every project subclassing it answers the command whether or not it keeps a
     * flag of its own to write. The refusal reaches the operator as the command's error
     * reply, which is the honest answer to a command aimed at the wrong installation.
     *
     * An implementation writes the row and NOTHING else. Telling the person's browsers is
     * the framework's ({@see self::announceAdminGrant()}); before HIL-729 it was part of
     * this seam, and each of the three demos wrote its own version of the announcement.
     * An unknown user is the implementation's to refuse, by throwing.
     *
     * @param int $userId Target user id, already validated as positive
     * @param bool $admin New admin flag
     * @throws NotImplementedException When the project has not wired the grant
     * @throws HilosException Whatever the project's grant implementation raises, an unknown user among it
     */
    protected function applyAdminGrant(int $userId, bool $admin): void
    {
        throw new NotImplementedException('Admin grant is not wired in this project');
    }

    /**
     * Resolves a handshake session token to a session row.
     *
     * Finds the session for the daemon-carried cookie token, creating an anonymous
     * one when the cookie is new. An authenticated session that has outlived its
     * expiry is downgraded to anonymous through {@see deauthenticateSession()} (the
     * HIL-398 drop) before it is returned; otherwise the session is touched to
     * refresh its last-seen and expiry. The caller (the project handshake handler)
     * registers the connection and emits the handshake response.
     *
     * @param string $sessionToken Daemon-resolved session cookie token (validated by the caller)
     * @return Session Resolved session, anonymous or authenticated
     * @throws InvalidFormatException When a new token is not a 32-character hex string
     * @throws DuplicateValueException When a concurrent create already claimed the token
     * @throws HilosException On database or runtime failure
     */
    private function resolveHandshakeSession(string $sessionToken): Session
    {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            return Hilos::$db->sessions->actions->createAnonymous($sessionToken);
        }

        $expiresAt = $session->expiresAt;
        if ($session->userId !== null
            && $expiresAt !== null
            && $expiresAt <= TimeHelper::getSqlDateTime()
        ) {
            // The cookie resolved to an authenticated session that has outlived its
            // expiry: drop it to anonymous before handing it back, so a stale cookie
            // can never resume an authenticated identity. A null expiry is open-ended.
            $this->logAgentInfo('session_expired ' . json_encode([
                'event' => 'session_expired',
                'session' => $session->id,
                'user' => $session->userId,
            ]));
            $this->deauthenticateSession($sessionToken);

            return Hilos::$db->sessions->findByToken($sessionToken) ?? $session;
        }

        $session->actions->touch();

        return $session;
    }

    /**
     * Authenticates a live session: rotates it onto a fresh token, binds it to a user,
     * authenticates the connection that initiated the login, and hands that connection
     * the ticket its browser trades for the new cookie.
     *
     * The upgrade seam login and register call to promote a session; the symmetric
     * downgrade is {@see deauthenticateSession()}. A no-op when the token has no
     * session row.
     *
     * Two things changed here in HIL-582, and both close the same session-fixation
     * attack. The token is ROTATED rather than kept, so a value someone planted in the
     * browser before the login stops naming this session the moment it succeeds; the
     * row itself is untouched, so its id, creation time, impersonator marker and
     * everything the analytics link to survive. And only the INITIATING connection is
     * authenticated, where every live connection of the session used to be: without
     * that, an attacker who had planted the cookie did not even need the cookie back -
     * opening a socket with the planted token beforehand and waiting was enough, since
     * the victim's login would have promoted his socket too.
     *
     * The rotation is announced but not delivered here, and since HIL-710 not even sent
     * here: the row is registered by this library and the ticket rides the state frame to
     * the project, which hands it to the browser behind the identity. The new token then
     * reaches the browser through the master's Set-Cookie on the next handshake, traded for
     * that one-time ticket; see {@see SessionRotationTicket}.
     *
     * A caller with no initiating connection passes null and gets no rotation - there is
     * no channel to deliver the ticket on, and nothing to rotate away from, since a token
     * nobody was handed was never planted. That path keeps the old behaviour whole: it
     * authenticates EVERY live connection of the session, because without a rotation they
     * all still belong to it, and the impersonation CLI acting on somebody else's session
     * must reach the tabs that session actually has. The parameter is required so that
     * saying "no initiator" is deliberate: a silent default would put the hole back for
     * every future caller that forgot.
     *
     * The ack a flow just earned is STATED here rather than written a frame earlier, and
     * that is the split doing its work (HIL-710). Before it, a caller marked the sockets and
     * then signed the session in, and the second step read the mark back off the rows it had
     * just written. Those rows belong to another process now: it has not applied the first
     * frame by the time this one is built, so reading them would answer with the ack of a
     * moment ago and this frame would state it away. One frame carries both, which is also
     * what the surface needs - the identity and the sentence about it arrive together.
     *
     * @param string $sessionToken Session cookie token to authenticate
     * @param int $userId Durable user id to bind the session to
     * @param ?string $initiatorAcceptKey Accept key of the connection that logged in, or null when there is none
     * @param ?string $ack Ack this ending earns (a {@see SessionAck} value), or null to restate what the sockets carry
     * @param ?string $requestId Request id of the action waiting on this ending, or null when nobody waits
     * @param ?string $action Action name the state frame answers, or null when it answers none
     * @param ?array<string, mixed> $outcome Reply the answer carries, or null for no domain reply
     * @return ?string Token the session answers to now, or null when the token named no session
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws HilosException On database or runtime failure
     * @throws RandomException When the platform's secure random source refuses a mint
     * @throws SessionTokenExhaustedException When three minted tokens in a row were already taken
     */
    private function authenticateSession(
        string $sessionToken,
        int $userId,
        ?string $initiatorAcceptKey,
        ?string $ack = null,
        ?string $requestId = null,
        ?string $action = null,
        ?array $outcome = null,
    ): ?string {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            return null;
        }

        // Told before the rotation, and with the token the attempts were counted under: the
        // throttle knows this session by what the browser presented, not by what it is about
        // to be handed (HIL-420).
        (new ThrottleGate())->reportAuthenticated($sessionToken);

        $rotated = $initiatorAcceptKey === null ? null : $this->rotateSessionToken($session, $userId);
        if ($rotated === null) {
            $session->actions->bindUser($userId);
        }
        $liveToken = $rotated ?? $sessionToken;

        // This session belongs to somebody now, so whatever registration it left
        // half-finished is over - by having just completed, or by having been abandoned
        // for a sign-in. Before HIL-612 this fell out by accident: the wait was keyed by
        // the token, and the rotation above orphaned it on a name nothing presented
        // again. The memory moved onto the row and now travels with it, so the release
        // has to be said out loud - and said HERE, above the state frame built below,
        // which would otherwise hand the freshly authenticated browser back the code
        // screen it just left.
        $session->actions->releasePendingRegistration();

        if ($rotated !== null) {
            // Analytics names a browser session by the token, not by the session row, so
            // the rotation has to be told - exactly as the runtime connection rows are.
            // Without it the visit before the login stays under a token nobody presents
            // again, and the identify below opens a second session for the same person.
            Hilos::$ac?->renameBrowserSession($sessionToken, $rotated);
        }
        Hilos::$ac?->identifyBrowserSessionUser($liveToken, $userId);

        if ($rotated === null) {
            // Nothing was rotated, so the session still answers to the token every one of
            // its connections named, and every one of them still belongs to it. Re-point
            // them all, exactly as this seam always did: the caller with no initiator is
            // acting on somebody else's session (the impersonation CLI), and the tabs of
            // that session have to learn who they are now.
            $this->publishSessionState(new SessionStateSignalData(
                sessionToken: $sessionToken,
                userId: $userId,
                acceptKeys: $this->sessionConnectionKeys($sessionToken),
                pendingAck: $ack ?? $this->sessionPendingAck($sessionToken),
                pendingAuthStep: $this->pendingAuthStepFor($session),
                requestId: $requestId,
                action: $action,
                outcome: $outcome,
            ));

            return $liveToken;
        }

        // The session's other connections are left anonymous on purpose: they are dropped
        // once the browser holds the new cookie, and they come back into the rotated
        // session by themselves. Authenticating them here is the second half of the attack
        // HIL-582 closes - a socket opened with a planted token would ride the victim's
        // login into her account. So the frame names the initiator alone, which is also
        // what makes it the rightful holder of the one-time ticket it carries.
        $keysToDrop = array_values(array_filter(
            $this->sessionConnectionKeys($sessionToken),
            static fn(string $acceptKey): bool => $acceptKey !== $initiatorAcceptKey,
        ));

        $pendingAck = $ack ?? $this->connectionPendingAck($initiatorAcceptKey);
        $this->publishSessionState(new SessionStateSignalData(
            sessionToken: $rotated,
            userId: $userId,
            acceptKeys: [$initiatorAcceptKey],
            pendingAck: $pendingAck,
            pendingAuthStep: $this->pendingAuthStepFor($session),
            rotationTicket: $this->announceRotation($rotated, $keysToDrop, $pendingAck),
            requestId: $requestId,
            action: $action,
            outcome: $outcome,
        ));

        return $liveToken;
    }

    /**
     * Moves a session onto a freshly minted token and binds it to the user in one write.
     *
     * Retries on a token another session already holds, which a 128-bit value makes a
     * theoretical event rather than an expected one - and that is exactly why the retry
     * is bounded and its exhaustion is an exception. Letting the login proceed on the old
     * token "so the user gets in" would restore the vulnerability in the one place where
     * it matters, so the login fails instead.
     *
     * @param Session $session Live session to rotate
     * @param int $userId Durable user id to bind the session to
     * @return string The token the session now answers to
     * @throws HilosException On database or runtime failure
     * @throws RandomException When the platform's secure random source refuses a mint
     * @throws SessionTokenExhaustedException When every attempt hit a token already in use
     */
    private function rotateSessionToken(Session $session, int $userId): string
    {
        for ($attempt = 0; $attempt < self::TOKEN_MINT_ATTEMPTS; $attempt++) {
            $candidate = SessionToken::mint();
            try {
                $session->actions->rotateTokenAndBindUser($candidate, $userId);

                return $candidate;
            } catch (DuplicateValueException) {
                // Another session holds the minted value; mint again.
            }
        }

        throw new SessionTokenExhaustedException(
            'Session token rotation failed: ' . self::TOKEN_MINT_ATTEMPTS . ' minted tokens were already in use'
        );
    }

    /**
     * Registers the pending rotation and returns the ticket the browser will trade for it.
     *
     * Order matters and is the mechanism, not a detail: the row has to exist before the
     * ticket is on the wire, or a browser fast enough to reconnect first would present a
     * ticket the master cannot find and lose the session. Returning the ticket instead of
     * sending it is how that order survives the split (HIL-710): the row is written here,
     * where the collection lives, and the frame carrying the ticket leaves after it.
     *
     * The initiator's pending ack rides on the row (HIL-423). The rotation ends the very
     * connection the ack was written on, so without this the sentence a flow just earned
     * dies with the socket that earned it, and the surface closes on a person who never
     * read it. The ticket is the one thing that says "the same browser, still in the flow
     * it started" — which is why the ack travels with it and not with the token.
     *
     * The ack is passed in rather than read off the initiator's row, and for the same reason
     * the frame states it (HIL-710): the row lives in another process and does not yet carry
     * what this very ending has just decided.
     *
     * @param string $newToken Token the session was rotated onto
     * @param list<string> $keysToDrop Accept keys of the session's other connections
     * @param ?string $pendingAck Ack the browser carries across the rotation, or null when it owes none
     * @return string One-time ticket the initiating browser trades for the rotated cookie
     * @throws HilosException On runtime failure
     * @throws RandomException When the platform's secure random source refuses a mint
     */
    private function announceRotation(string $newToken, array $keysToDrop, ?string $pendingAck): string
    {
        $ticket = SessionRotationTicket::mint();
        Hilos::$rt?->hilosSessionRotations->actions->register(
            $ticket,
            $newToken,
            $keysToDrop,
            SessionRotationTicket::expiryFromNow(),
            $pendingAck,
        );

        return $ticket;
    }

    /**
     * Reverts a live session to anonymous and tells its sockets so. The inverse of
     * {@see authenticateSession()}.
     *
     * The session row and token are kept — the session simply becomes anonymous
     * again. A no-op when the token has no session row or is already anonymous.
     * Presence follows the connection re-point the project makes on the frame: a user with
     * no other authenticated connection drops offline through the standard connection sync.
     *
     * This is also the one seam every way a live session loses its person passes
     * through — the shell sign-out, the expiry drop above, the account-merge force-logout
     * and the recovery drop of the other sessions — which is why every one of them ends in
     * exactly one frame, and why the page re-decision of HIL-652 has exactly one place to
     * stand on the far side of it.
     *
     * @param string $sessionToken Session cookie token to revert to anonymous
     * @param ?string $requestId Request id of the action waiting on this ending, or null when nobody waits
     * @param ?string $action Action name the state frame answers, or null when it answers none
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws HilosException On database or runtime failure
     */
    private function deauthenticateSession(
        string $sessionToken,
        ?string $requestId = null,
        ?string $action = null,
    ): void {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null || $session->userId === null) {
            return;
        }

        $session->actions->unbindUser();

        $this->publishSessionState(new SessionStateSignalData(
            sessionToken: $sessionToken,
            userId: null,
            acceptKeys: $this->sessionConnectionKeys($sessionToken),
            pendingAck: $this->sessionPendingAck($sessionToken),
            requestId: $requestId,
            action: $action,
        ));
    }

    /**
     * Reverts every session of a user to anonymous EXCEPT one (HIL-416).
     *
     * What a finished password recovery owes the account. A password is reset when
     * access to it has leaked, so returning the account means returning it whole: the
     * sessions someone else may be holding go, and the one that just proved the
     * mailbox stays. Doing it the other way round - dropping everything and asking the
     * person to sign in again with the password they typed thirty seconds ago - throws
     * away the proof they just gave.
     *
     * Each session goes through {@see deauthenticateSession()}, the same seam logout
     * and the merge force-logout use, so the session row and its cookie survive as
     * anonymous and the live connections learn about it. The kept token does not have
     * to be one of the user's sessions - a token that is not among them simply keeps
     * nothing, which is the honest answer for a caller that has none.
     *
     * Reaches the connections of THIS node only, exactly like the logout it is built
     * from; a session held open on another node of a cluster keeps its socket until
     * that node hears about the row. That limit belongs to the seam and is not
     * recovery's to fix.
     *
     * @param int $userId User whose other sessions are dropped
     * @param string $keepSessionToken Session token that stays signed in
     * @throws HilosException On database or runtime failure
     */
    private function deauthenticateOtherSessions(int $userId, string $keepSessionToken): void
    {
        foreach (Hilos::$db->sessions->findByUserId($userId) as $session) {
            if ($session->token === $keepSessionToken) {
                continue;
            }

            $this->deauthenticateSession($session->token);
        }
    }

    /**
     * Clears the pending ack from every live socket of a session (HIL-422).
     *
     * What the Continue button ends up calling. The truth is the row, so the surface
     * disappears when the cleared mark comes back through the projection rather than on
     * the click — which is also what makes the second tab close its copy, and what makes
     * a double click harmless: the second one marks rows that already carry null.
     *
     * @param string $sessionToken Session cookie token whose sockets are cleared
     * @param ?string $requestId Request id of the action waiting on this ending, or null when nobody waits
     * @param ?string $action Action name the state frame answers, or null when it answers none
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws HilosException On database or runtime failure
     */
    private function clearSessionAck(string $sessionToken, ?string $requestId = null, ?string $action = null): void
    {
        $this->republishSessionAck($sessionToken, null, $requestId, $action);
    }

    /**
     * States one ack on every live socket of a session, in the frame that re-publishes it.
     *
     * The write and the re-publish are one step on purpose: the frontend draws from the
     * projection alone, so a mark nobody published is a mark nobody sees, and the two
     * drifting apart is the only way this mechanism can fail silently. Since HIL-710 that
     * is guaranteed by there being one frame rather than by two calls kept side by side -
     * the project writes the row and sends the response out of the same handler.
     *
     * A token no session answers to is a no-op, the same guard {@see deauthenticateSession()}
     * takes and for a sharper reason: sockets CAN outlive their token. The login rotation
     * re-points only the connection that initiated it, so the session's other sockets go on
     * naming a token the row no longer has — and building the response anyway would resolve
     * that token to no session and publish `currentUser: null`, signing those tabs out of
     * their own shell to tell them an announcement was dismissed. They are dropped by the
     * rotation moments later and come back knowing who they are; saying nothing until then
     * is the honest answer.
     *
     * @param string $sessionToken Session cookie token whose sockets are written
     * @param ?string $ack Ack kind to show (a {@see SessionAck} value), or null to clear it
     * @param ?string $requestId Request id of the action waiting on this ending, or null when nobody waits
     * @param ?string $action Action name the state frame answers, or null when it answers none
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws HilosException On database or runtime failure
     */
    private function republishSessionAck(
        string $sessionToken,
        ?string $ack,
        ?string $requestId = null,
        ?string $action = null,
    ): void {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            return;
        }

        $this->publishSessionState(new SessionStateSignalData(
            sessionToken: $sessionToken,
            userId: $session->userId,
            acceptKeys: $this->sessionConnectionKeys($sessionToken),
            pendingAck: $ack,
            pendingAuthStep: $this->pendingAuthStepFor($session),
            requestId: $requestId,
            action: $action,
        ));
    }

    /**
     * Ends every browser's wait on a just-registered identifier, each in its own way (HIL-415).
     *
     * The converge half of reserve-on-submit registration, and the place where the
     * ownership rule becomes visible (HIL-608). Several sessions can legitimately have
     * been waiting on one identifier, and they are NOT the same to it: the tabs of the
     * browser that won are the same person finishing what they started, so they are
     * signed into the new account and moved to the done step; every other browser was
     * racing for the identifier and lost it, so it is told the address is taken and sent
     * back to the identifier field under the sign-in intent - never subscribed into an
     * account it has no claim to. That subscription, made to whoever happened to be
     * parked, was the second door of the same capture the reservation key closed.
     *
     * NOBODY is signed in here, and the winner's own tabs are no exception (HIL-649). They
     * are moved to the done step and nothing more: the rotation the confirmation ran is what
     * puts them back into the session, and the sentence it earned reaches them on their own
     * handshake. Signing them in from here was tried and never once ran - the call was handed
     * the pre-rotation token and returned at its first line from HIL-582 onward - and it
     * could not be repaired by passing the live one either, since that would have rotated the
     * token once per neighbouring tab. The confirming connection is skipped entirely: its
     * caller signed it in on the ordinary path and answered it with the action reply.
     *
     * The losing SESSIONS are named as well as parked ones, because a browser can hold an
     * identifier without ever having been parked on it: a magic-link ask writes a hold and
     * no wait, and its check-your-inbox screen would otherwise sit there until the link it
     * is waiting for turned out to be worthless.
     *
     * The durable waits are dropped HERE, and only after the waiting sockets have been
     * read off them (HIL-486): they are half of who is waiting, so a caller that cleared
     * them first would converge to whoever happened to be parked in the runtime list and
     * silently miss the rest.
     *
     * @param string $identifier Normalized identifier that was just confirmed (lowercased email)
     * @param int $userId User the confirmation created
     * @param string $initiatorAcceptKey Accept key of the connection that submitted the proof
     * @param string $winnerSessionToken Session cookie token of the browser whose registration this was
     * @param list<string> $losingSessionTokens Session tokens whose hold on the identifier was dropped
     * @throws HilosException On runtime, database, or session failure
     */
    private function convergeRegistration(
        string $identifier,
        int $userId,
        string $initiatorAcceptKey,
        string $winnerSessionToken,
        array $losingSessionTokens,
    ): void {
        $parked = $this->parkedAcceptKeys($identifier);
        foreach ($losingSessionTokens as $sessionToken) {
            foreach ($this->sessionConnectionKeys($sessionToken) as $acceptKey) {
                $parked[$acceptKey] = $sessionToken;
            }
        }

        Hilos::$db->sessions->actions->releasePendingRegistrationFor($identifier);

        foreach ($parked as $acceptKey => $sessionToken) {
            Hilos::$rt->hilosRegistrationWaiters->actions->release($acceptKey);
            if ($acceptKey === $initiatorAcceptKey) {
                continue;
            }

            if ($sessionToken !== $winnerSessionToken) {
                $this->sendToUser(
                    HilosSignalConstants::HILOS_AUTH_CONVERGE,
                    $acceptKey,
                    new AuthConvergeSignalData(
                        $acceptKey,
                        $identifier,
                        AuthFlowStep::IDENTIFIER,
                        AuthFlowIntent::LOGIN,
                        AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
                    ),
                );

                continue;
            }

            $this->sendToUser(
                HilosSignalConstants::HILOS_AUTH_CONVERGE,
                $acceptKey,
                new AuthConvergeSignalData($acceptKey, $identifier, AuthFlowStep::DONE, AuthFlowIntent::REGISTER),
            );
        }
    }

    /**
     * Ends one session's wait on a registration, in every tab of it (HIL-486).
     *
     * The whole of "not that address?": the session forgets the address it was on, the
     * sockets parked on it are dropped, and the other tabs of the same session are told to
     * go back to the identifier field. The initiator is skipped because its own action
     * reply already moves it - a second order for the same move would race the first.
     *
     * Both halves happen HERE because both are the session's (HIL-622). Until the sign-in
     * commands moved to the users library, the durable half was dropped by the page that
     * took the action; the library that took its place owns no session and cannot.
     *
     * Only sockets of THIS session are touched. Another session waiting on the same
     * identifier is still waiting on it, and the hold nobody released still stands.
     *
     * @param string $sessionToken Session cookie token walking away from its registration
     * @param string $initiatorAcceptKey Accept key that asked, answered by its own action reply
     * @throws HilosException On runtime or database failure
     */
    private function abandonRegistration(string $sessionToken, string $initiatorAcceptKey): void
    {
        // Two passes for the ordering below, not for the walk: the durable release has to
        // land between reading the waiters and telling them, so the reading finishes first.
        $parked = [];
        foreach (Hilos::$rt->hilosRegistrationWaiters as $waiter) {
            if ($waiter->sessionToken === $sessionToken) {
                $parked[$waiter->acceptKey] = $waiter->identifier;
            }
        }

        // The durable memory goes ahead of the signals, for the reason the expiry sweep
        // drops it in the same order: a tab reconnecting a moment later must be told the
        // identifier step by the handshake, not parked again on a screen this call closes.
        Hilos::$db->sessions->findByToken($sessionToken)?->actions->releasePendingRegistration();

        foreach ($parked as $acceptKey => $identifier) {
            Hilos::$rt->hilosRegistrationWaiters->actions->release($acceptKey);
            if ($acceptKey === $initiatorAcceptKey) {
                continue;
            }

            $this->sendToUser(
                HilosSignalConstants::HILOS_AUTH_CONVERGE,
                $acceptKey,
                new AuthConvergeSignalData(
                    $acceptKey,
                    $identifier,
                    AuthFlowStep::IDENTIFIER,
                    AuthFlowIntent::REGISTER,
                ),
            );
        }
    }

    /**
     * Opens the password step in the other tabs of a session that just proved a code (HIL-416).
     *
     * The push half of session-binding. A code accepted in one tab is accepted for the
     * session, so the tabs that were sitting on the code screen of the same address are
     * moved forward with it - otherwise the person would be looking at two windows of
     * one browser disagreeing about which screen they are on, and typing the code again
     * in the second would cost an attempt for nothing.
     *
     * The rows stay parked: the grant is written on them and the wait is not over yet.
     * The answering connection is skipped - its caller answered it with the action reply.
     *
     * @param string $identifier Normalized address being recovered (lowercased email)
     * @param string $sessionToken Session token that just proved the code
     * @param string $initiatorAcceptKey Accept key of the connection that submitted the code
     * @throws HilosException On runtime failure
     */
    private function grantRecoveryToSession(
        string $identifier,
        string $sessionToken,
        string $initiatorAcceptKey,
    ): void {
        foreach (Hilos::$rt->hilosRecoveryWaiters->forSessionToken($sessionToken) as $waiter) {
            if ($waiter->acceptKey === $initiatorAcceptKey || $waiter->identifier !== $identifier) {
                continue;
            }

            $this->sendToUser(
                HilosSignalConstants::HILOS_AUTH_CONVERGE,
                $waiter->acceptKey,
                new AuthConvergeSignalData(
                    $waiter->acceptKey,
                    $identifier,
                    AuthFlowStep::SET_PASSWORD,
                    AuthFlowIntent::RECOVERY,
                ),
            );
        }
    }

    /**
     * Settles an address for everyone the moment one session saves its new password (HIL-416).
     *
     * The converge half of recovery, and the reason nobody has to be refused a code:
     * two devices may both reach the password screen, and what ends the race is the
     * save, not the code. The code is single-use, so the first save spends it and every
     * other waiter is now holding a grant that buys nothing - they are told so rather
     * than left to discover it by typing a password into a refusal.
     *
     * Where they are sent depends on whose they are, and the split is the whole of
     * session-binding seen from the other end: the tabs of the session that saved are
     * signed in along with it, so they get the done step under recovery, exactly like
     * the tab that submitted; the sessions of other devices get the identifier step
     * under sign-in, with the news that the password is already the new one. Every row
     * goes either way - the wait is over for all of them.
     *
     * No ack is marked here, and the asymmetry with {@see convergeRegistration()} is the
     * mechanism rather than an omission (HIL-422). The waiters that go to done are on the
     * saving session itself, whose sockets were all marked before it was signed in, so
     * they already carry the announcement; the waiters on OTHER sessions must NOT get one
     * - nothing was achieved on their device, and what they are owed is the inline
     * "already changed" line this method sends them.
     *
     * @param string $identifier Normalized address whose password was just saved (lowercased email)
     * @param string $sessionToken Session token that saved it, whose tabs go to done
     * @param string $initiatorAcceptKey Accept key of the connection that submitted the password
     * @throws HilosException On runtime failure
     */
    private function convergeRecovery(
        string $identifier,
        string $sessionToken,
        string $initiatorAcceptKey,
    ): void {
        foreach ($this->parkedRecoveryAcceptKeys($identifier) as $acceptKey => $parkedSessionToken) {
            Hilos::$rt->hilosRecoveryWaiters->actions->release($acceptKey);
            if ($acceptKey === $initiatorAcceptKey) {
                continue;
            }

            $isSameSession = $parkedSessionToken === $sessionToken;

            $this->sendToUser(
                HilosSignalConstants::HILOS_AUTH_CONVERGE,
                $acceptKey,
                new AuthConvergeSignalData(
                    $acceptKey,
                    $identifier,
                    $isSameSession ? AuthFlowStep::DONE : AuthFlowStep::IDENTIFIER,
                    $isSameSession ? AuthFlowIntent::RECOVERY : AuthFlowIntent::LOGIN,
                    $isSameSession ? null : AuthFlowOutcome::CODE_PASSWORD_ALREADY_CHANGED,
                ),
            );
        }
    }

    /**
     * Routes one frame addressed to this library - seven from the users library, two back
     * over the project seam (HIL-622, HIL-710, HIL-729), and one from whoever has something to
     * say to a browser (HIL-768).
     *
     * The switch is the framework's rather than a project's because what each frame means
     * is: the users library ends a ceremony by saying what happened, and the order this
     * library then acts in - mark the sockets, raise the session, settle the tabs, answer -
     * is the mechanism itself (HIL-422). A project that re-implemented it could only ever
     * re-implement it differently.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one of this library's frames
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws HilosException On database, runtime, or session failure
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws InvalidArgumentException When a frame or a reply cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_AUTH_SESSION_GRANT:
                if (!$data->data instanceof AuthSessionGrantSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AuthSessionGrantSignalData::class, $data->data);
                }

                $this->grantSessionToUser($data->data);

                return;

            case HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED:
                if (!$data->data instanceof AuthRegistrationLandedSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        AuthRegistrationLandedSignalData::class,
                        $data->data,
                    );
                }

                $this->settleLandedRegistration($data->data);

                return;

            case HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED:
                if (!$data->data instanceof AuthRecoveryGrantedSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        AuthRecoveryGrantedSignalData::class,
                        $data->data,
                    );
                }

                $this->openRecoveryPasswordStep($data->data);

                return;

            case HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED:
                if (!$data->data instanceof AuthPasswordChangedSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        AuthPasswordChangedSignalData::class,
                        $data->data,
                    );
                }

                $this->settleChangedPassword($data->data);

                return;

            case HilosSignalConstants::HILOS_AUTH_REGISTRATION_ABANDONED:
                if (!$data->data instanceof AuthRegistrationAbandonedSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        AuthRegistrationAbandonedSignalData::class,
                        $data->data,
                    );
                }

                $this->dropAbandonedRegistration($data->data);

                return;

            case HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED:
                if (!$data->data instanceof AuthRegistrationWaitMovedSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        AuthRegistrationWaitMovedSignalData::class,
                        $data->data,
                    );
                }

                $this->moveRegistrationWait($data->data);

                return;

            case HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED:
                if (!$data->data instanceof AuthRecoveryWaitMovedSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        AuthRecoveryWaitMovedSignalData::class,
                        $data->data,
                    );
                }

                $this->moveRecoveryWait($data->data);

                return;

            case HilosSignalConstants::HILOS_SESSION_REBIND:
                if (!$data->data instanceof SessionRebindSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SessionRebindSignalData::class, $data->data);
                }

                $this->rebindSession($data->data);

                return;

            case HilosSignalConstants::HILOS_ACCOUNT_MERGE:
                if (!$data->data instanceof AccountMergeSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AccountMergeSignalData::class, $data->data);
                }

                $this->handleAccountMergeRequest($data->data);

                return;

            case HilosSignalConstants::HILOS_SESSION_TOAST_RAISE:
                if (!$data->data instanceof RaiseSessionToastSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        RaiseSessionToastSignalData::class,
                        $data->data,
                    );
                }

                $this->raiseSessionToast($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Puts one card on a session's stack and shows it to every tab of that session (HIL-768).
     *
     * The card's name is minted HERE, which is what stops a sender from addressing one that
     * already exists: the sender says what to show and to whom, and everything about which
     * card that becomes - a new one, or one more count on the identical one already up - is
     * decided where the stack is stored. The form is the rotation ticket's, for want of a
     * reason to invent a second one.
     *
     * A session with no live socket is told NOTHING, and no row is made for it. A toast lives
     * only while somebody may be looking at it, so a card for a browser that has closed would
     * be a row waiting for a reader who, by the time they arrive, is being told about
     * something that finished long ago.
     *
     * @param RaiseSessionToastSignalData $frame Session to tell, and what to say
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the toast frame cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot mint the card's name
     */
    private function raiseSessionToast(RaiseSessionToastSignalData $frame): void
    {
        $stacks = Hilos::$rt?->hilosSessionToastStacks;
        if ($stacks === null) {
            return;
        }

        $liveAcceptKeys = $this->liveAcceptKeysBySessionTokenHash()[$frame->sessionTokenHash] ?? [];
        if ($liveAcceptKeys === []) {
            return;
        }

        $stacks->actions->raise(
            $frame->sessionTokenHash,
            RandomHelper::secureHex(self::TOAST_KEY_RANDOM_BYTES),
            $frame->message,
            $frame->severity,
            $frame->source,
            $frame->destination,
        );
        $this->publishSessionToasts($frame->sessionTokenHash);
    }

    /**
     * Takes one card off a session's stack because a person closed it.
     *
     * @param string $sessionToken Session cookie token of the acting connection
     * @param string $key Name of the card being closed
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the toast frame cannot be named or queued
     */
    private function dismissSessionToast(string $sessionToken, string $key): void
    {
        $stacks = Hilos::$rt?->hilosSessionToastStacks;
        if ($stacks === null) {
            return;
        }

        $sessionTokenHash = StateProtectedModeRuntime::hashSessionToken($sessionToken);
        if ($stacks->actions->dismiss($sessionTokenHash, $key)) {
            $this->publishSessionToasts($sessionTokenHash);
        }
    }

    /**
     * Notes that one tab's countdown for a card has finished, then re-decides the stack.
     *
     * The write and the judgement are two steps because they answer different questions, and
     * the second one has to weigh what the first tab cannot see: a cursor resting on the stack
     * in another window holds every card in it, so a countdown that finished here waits.
     *
     * @param string $sessionToken Session cookie token of the acting connection
     * @param string $key Name of the card whose countdown finished
     * @param string $acceptKey Accept key of the tab reporting it
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the toast frame cannot be named or queued
     */
    private function reportSessionToastExpired(string $sessionToken, string $key, string $acceptKey): void
    {
        $stacks = Hilos::$rt?->hilosSessionToastStacks;
        if ($stacks === null) {
            return;
        }

        $sessionTokenHash = StateProtectedModeRuntime::hashSessionToken($sessionToken);
        $stacks->actions->markExpired($sessionTokenHash, $key, $acceptKey);
        if ($stacks->actions->settle($sessionTokenHash, $this->sessionConnectionKeys($sessionToken))) {
            $this->publishSessionToasts($sessionTokenHash);
        }
    }

    /**
     * Notes whether the stack is being read in one tab, and lets go of what was waiting on it.
     *
     * A hold ending is re-decided at once rather than left to the next tick: the ticket says a
     * neighbour's finished countdown WAITS instead of firing, so the moment the last reader
     * leaves is the moment it fires. Taking a hold changes nothing by itself - nothing can go
     * away while somebody is reading - so only the releasing edge asks for a judgement.
     *
     * @param string $sessionToken Session cookie token of the acting connection
     * @param string $acceptKey Accept key of the tab that started or stopped reading
     * @param bool $reading Whether that tab is reading the stack now
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the toast frame cannot be named or queued
     */
    private function setSessionToastReading(string $sessionToken, string $acceptKey, bool $reading): void
    {
        $stacks = Hilos::$rt?->hilosSessionToastStacks;
        if ($stacks === null) {
            return;
        }

        $sessionTokenHash = StateProtectedModeRuntime::hashSessionToken($sessionToken);
        $stacks->actions->setReading($sessionTokenHash, $acceptKey, $reading);
        if ($reading) {
            return;
        }

        if ($stacks->actions->settle($sessionTokenHash, $this->sessionConnectionKeys($sessionToken))) {
            $this->publishSessionToasts($sessionTokenHash);
        }
    }

    /**
     * Makes one session say what a project asked it to say (HIL-710).
     *
     * The whole of the way back over the seam. The frame names the TARGET state, so the
     * operation falls out of it rather than being carried beside it: a null user is a
     * sign-out, any other is a bind. The impersonation marker is written FIRST, exactly
     * where the single-process version wrote it, because the identity that goes out on the
     * frame below reads it - a marker set afterwards would announce the takeover one frame
     * late, and a marker cleared afterwards would announce it one frame too long.
     *
     * The marker is written only when it actually changes: a sign-out that names the same
     * administrator is not asking for a write, and a session row re-synced for nothing
     * would fan out to every reader of it.
     *
     * The operator is answered from HERE and not by the project that asked, which is the
     * point of carrying a correlation id at all: after the split nobody else can see what
     * happened, so the project would have had to answer "accepted" and leave a mistyped
     * token looking like a success.
     *
     * @param SessionRebindSignalData $frame Session, the state it must reach, and whom to answer
     * @throws HilosException On database or runtime failure
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws InvalidArgumentException When the state frame or the reply cannot be named
     */
    private function rebindSession(SessionRebindSignalData $frame): void
    {
        $session = Hilos::$db->sessions->findByToken($frame->sessionToken);
        if ($session === null) {
            $this->replyToRebind($frame, 'No session for that token');

            return;
        }

        if ($session->impersonatorUserId !== $frame->impersonatorUserId) {
            $session->actions->setImpersonator($frame->impersonatorUserId);
        }

        $liveToken = $frame->userId === null
            ? $this->deauthenticateSessionAndKeepToken($frame->sessionToken)
            : $this->authenticateSession($frame->sessionToken, $frame->userId, $frame->initiatorAcceptKey);

        $this->replyToRebind($frame, null, $liveToken ?? $frame->sessionToken);
    }

    /**
     * Reverts a session to anonymous and names the token it still answers to.
     *
     * The sign-out half of {@see rebindSession()}, which needs a token to report back;
     * signing out rotates nothing, so the token is the one that came in.
     *
     * @param string $sessionToken Session cookie token to revert to anonymous
     * @return string The token the session answers to, unchanged by a sign-out
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws HilosException On database or runtime failure
     */
    private function deauthenticateSessionAndKeepToken(string $sessionToken): string
    {
        $this->deauthenticateSession($sessionToken);

        return $sessionToken;
    }

    /**
     * Tells the operator behind a rebind what became of the session, when one is waiting.
     *
     * Nothing is sent for a frame carrying no correlation id: the same rebind is asked for
     * by a browser action, whose answer is the identity frame itself.
     *
     * @param SessionRebindSignalData $frame Rebind that was carried out or refused
     * @param ?string $error Why it was refused, or null when it was carried out
     * @param ?string $liveToken Token the session answers to now, or null when it was refused
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     * @throws HilosException When the session read-back fails
     */
    private function replyToRebind(SessionRebindSignalData $frame, ?string $error, ?string $liveToken = null): void
    {
        $correlationId = $frame->correlationId;
        if ($correlationId === null) {
            return;
        }

        if ($error !== null) {
            $this->replyToCommand(CommandReplyDTO::error($correlationId, $error));

            return;
        }

        $session = $liveToken === null ? null : Hilos::$db->sessions->findByToken($liveToken);
        $this->replyToCommand(CommandReplyDTO::ok($correlationId, [
            SessionRebindConstants::FIELD_SESSION_TOKEN => $liveToken,
            SessionRebindConstants::FIELD_USER_ID => $session?->userId,
            SessionRebindConstants::FIELD_IMPERSONATOR_USER_ID => $session?->impersonatorUserId,
        ]));
    }

    /**
     * Runs one of the page-independent controls a browser has over its own session.
     *
     * Every one of them takes its session from the ACTING connection, read off the project's
     * own connection rows - which this library may read but never write. A connection carrying
     * no session is refused for all seven, because there is nothing to end, dismiss, vacate or
     * answer about, and returning was read by the browser as having done it (HIL-730). Signing
     * out is not the exception it looks like: the token is gone from the runtime but the cookie
     * is still in the browser, so "you are signed out" would be undone by the next reload.
     *
     * One sentence covers all seven, because the reason is not about the action: the connection
     * does not carry a session.
     *
     * None of them answers here. The first four end in a session state, which the project
     * puts on the wire, so the answer is carried in that frame and leaves behind the
     * identity it announces (HIL-622). The impersonation pair is the same shape - a takeover
     * a browser asked for is answered by the identity it gets back, not by an ack - so the
     * correlation id it hands the core is null; only an operator on the command socket has
     * one (HIL-729). The three toast controls end in a toast frame instead (HIL-768), and it
     * goes to the whole SESSION rather than to the tab that spoke: the tabs agreeing is the
     * answer, and a tab that only heard about its own click would be the disagreement again.
     *
     * A refused takeover is the exception, and it needs no frame of its own: a guard throws,
     * and the dispatcher turns that into the action-fail ack the caller is already awaiting.
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the answer travels on a state or toast frame instead
     * @throws SessionNotOnConnectionException When the acting connection carries no session
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ValidationException When an impersonation guard rejects the request
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws HilosException When ending the session exposes database or runtime failure
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        $sessionToken = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->sessionToken;
        if ($sessionToken === null || $sessionToken === '') {
            throw new SessionNotOnConnectionException(self::SESSION_NOT_ON_CONNECTION_MESSAGE);
        }

        switch ($action) {
            case HilosSignalConstants::HILOS_LOGOUT:
                if (!$dto instanceof LogoutActionDTO) {
                    throw new InvalidActionPayloadException($action, LogoutActionDTO::class, $dto);
                }
                $this->deauthenticateSession($sessionToken, $this->currentActionRequestId(), $action);

                return null;

            case HilosSignalConstants::HILOS_DISMISS_SESSION_ACK:
                if (!$dto instanceof DismissSessionAckActionDTO) {
                    throw new InvalidActionPayloadException($action, DismissSessionAckActionDTO::class, $dto);
                }
                $this->clearSessionAck($sessionToken, $this->currentActionRequestId(), $action);

                return null;

            case HilosSignalConstants::HILOS_IMPERSONATE_START:
                if (!$dto instanceof ImpersonateStartActionDTO) {
                    throw new InvalidActionPayloadException($action, ImpersonateStartActionDTO::class, $dto);
                }
                $this->startImpersonation($sessionToken, $dto->targetUserId, $acceptKey, null);

                return null;

            case HilosSignalConstants::HILOS_IMPERSONATE_STOP:
                if (!$dto instanceof ImpersonateStopActionDTO) {
                    throw new InvalidActionPayloadException($action, ImpersonateStopActionDTO::class, $dto);
                }
                $this->stopImpersonation($sessionToken, $acceptKey, null);

                return null;

            case HilosSignalConstants::HILOS_TOAST_DISMISS:
                if (!$dto instanceof DismissSessionToastActionDTO) {
                    throw new InvalidActionPayloadException($action, DismissSessionToastActionDTO::class, $dto);
                }
                $this->dismissSessionToast($sessionToken, $dto->key);

                return null;

            case HilosSignalConstants::HILOS_TOAST_EXPIRED:
                if (!$dto instanceof SessionToastExpiredActionDTO) {
                    throw new InvalidActionPayloadException($action, SessionToastExpiredActionDTO::class, $dto);
                }
                $this->reportSessionToastExpired($sessionToken, $dto->key, $acceptKey);

                return null;

            case HilosSignalConstants::HILOS_TOAST_READING:
                if (!$dto instanceof SessionToastReadingActionDTO) {
                    throw new InvalidActionPayloadException($action, SessionToastReadingActionDTO::class, $dto);
                }
                $this->setSessionToastReading($sessionToken, $acceptKey, $dto->reading);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Routes a CLI command sent to this library.
     *
     * The six names of {@see self::AGENT_COMMANDS}; anything else gets an error reply
     * rather than silence, because the socket parks the caller until it is answered.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused)
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($data->command === CliCommands::ADMIN_CREATE) {
            $this->handleAdminCreateCommand($data);

            return;
        }

        if ($data->command === CliCommands::ADMIN_GRANT || $data->command === CliCommands::ADMIN_REVOKE) {
            $this->handleAdminGrantCommand($data);

            return;
        }

        if ($data->command === CliCommands::IMPERSONATE_START) {
            $this->handleImpersonateStartCommand($data);

            return;
        }

        if ($data->command === CliCommands::IMPERSONATE_STOP) {
            $this->handleImpersonateStopCommand($data);

            return;
        }

        if ($data->command === CliCommands::ACCOUNT_MERGE) {
            $this->handleAccountMergeCommand($data);

            return;
        }

        $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
    }

    /**
     * Runs {@see CliCommands::IMPERSONATE_START} for an operator: reads the session token and
     * the target off the command payload and hands the core the correlation id to answer on.
     *
     * Only a REFUSAL is answered here. What the session actually became is reported from
     * where it was written ({@see self::replyToRebind()}), which carries the token the
     * session answers to afterwards - a rotated one, when the bind rotated it. Answering
     * "accepted" from here instead would make a mistyped token look like a success.
     *
     * Every failure is caught, including the project's own from
     * {@see self::assertImpersonationAllowed()}: a command failure that reached the worker
     * loop would leave the operator parked on the socket with nothing coming back.
     *
     * @param CommandRequestDTO $data Command request carrying the session token and target user id
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    private function handleImpersonateStartCommand(CommandRequestDTO $data): void
    {
        // The command socket authenticates nobody, so the payload is whatever was typed at
        // it; an absent token names no session and an absent target no user, and the guards
        // below refuse both rather than reading them as a blank session and a user id of 0.
        // external-boundary: an operator's command line, refused by the guards below
        $sessionToken = (string)($data->payload[AdminCommandConstants::FIELD_SESSION_TOKEN] ?? '');
        $targetUserId = (int)($data->payload[AdminCommandConstants::FIELD_TARGET_USER_ID] ?? 0);

        try {
            $this->startImpersonation($sessionToken, $targetUserId, null, $data->correlationId);
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));
        }
    }

    /**
     * Runs {@see CliCommands::IMPERSONATE_STOP} for an operator: reads the session token off
     * the command payload and hands the core the correlation id to answer on.
     *
     * Answers a refusal only, for the reason {@see self::handleImpersonateStartCommand()}
     * gives; the administrator the session goes back to is read off the row and reported
     * from there.
     *
     * @param CommandRequestDTO $data Command request carrying the session token
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    private function handleImpersonateStopCommand(CommandRequestDTO $data): void
    {
        // external-boundary: an operator's command line, refused by the guards below
        $sessionToken = (string)($data->payload[AdminCommandConstants::FIELD_SESSION_TOKEN] ?? '');

        try {
            $this->stopImpersonation($sessionToken, null, $data->correlationId);
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));
        }
    }

    /**
     * Makes one admin session act as another user - the core behind both ways in (HIL-166).
     *
     * Guards, in order: the session must exist; it must carry a user at all; that user must
     * be allowed to take the target over, which is the project's answer
     * ({@see self::assertImpersonationAllowed()}); the session must not already be
     * impersonating (no nesting); and the target must differ from the person asking.
     *
     * The order is what keeps the refusals honest rather than an accident of writing. The
     * project seam stands where the admin check stood before the move, so a session that is
     * already impersonating still fails as "not an admin session" - its current user is the
     * non-admin target, not the administrator behind it - and never gets far enough to learn
     * whether some other user id exists.
     *
     * The write is {@see self::rebindSession()}, called rather than signalled: before HIL-729
     * the guards ran in a project agent and had to ASK for the bind over a frame, and the
     * whole of what this move buys is that the two halves are now one process. The frame it
     * builds is unchanged, marker and all, which is why the ordering that frame documents -
     * the impersonator written before the bind - still holds.
     *
     * @param string $sessionToken Session cookie token of the acting admin session
     * @param int $targetUserId User id to impersonate
     * @param ?string $initiatorAcceptKey Accept key of the admin's connection, or null for the CLI path
     * @param ?string $correlationId Command correlation id to answer the operator on, or null for a browser
     * @throws ValidationException When a guard rejects the request
     * @throws NotImplementedException When the project has not wired the impersonation seam
     * @throws InvalidArgumentException When the state frame or the reply cannot be named
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws HilosException On database or runtime failure
     */
    private function startImpersonation(
        string $sessionToken,
        int $targetUserId,
        ?string $initiatorAcceptKey,
        ?string $correlationId,
    ): void {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            throw new ValidationException('No such session');
        }

        $adminId = $session->userId;
        if ($adminId === null) {
            throw new ValidationException('Session is not an admin session');
        }

        $this->assertImpersonationAllowed($adminId, $targetUserId);

        if ($session->impersonatorUserId !== null) {
            throw new ValidationException('Already impersonating; stop impersonating first');
        }

        if ($targetUserId === $adminId) {
            throw new ValidationException('Cannot impersonate yourself');
        }

        $this->rebindSession(new SessionRebindSignalData(
            sessionToken: $sessionToken,
            userId: $targetUserId,
            impersonatorUserId: $adminId,
            initiatorAcceptKey: $initiatorAcceptKey,
            correlationId: $correlationId,
        ));

        $this->logAgentInfo('impersonate_start ' . json_encode([
            'event' => 'impersonate_start',
            'admin' => $adminId,
            'target' => $targetUserId,
            'session' => $session->id,
        ]));
    }

    /**
     * Returns one impersonating session to the administrator behind it - the inverse of
     * {@see self::startImpersonation()} and the core behind both ways in (HIL-166).
     *
     * No seam and no project field: the administrator to go back to is on the session's own
     * marker, so nobody has to be asked whether this is allowed. Ending a takeover is
     * allowed to whoever is inside it, exactly as signing out is.
     *
     * @param string $sessionToken Session cookie token of the impersonating session
     * @param ?string $initiatorAcceptKey Accept key of the requesting connection, or null for the CLI path
     * @param ?string $correlationId Command correlation id to answer the operator on, or null for a browser
     * @throws ValidationException When the session is missing or not impersonating
     * @throws InvalidArgumentException When the state frame or the reply cannot be named
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws HilosException On database or runtime failure
     */
    private function stopImpersonation(
        string $sessionToken,
        ?string $initiatorAcceptKey,
        ?string $correlationId,
    ): void {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            throw new ValidationException('No such session');
        }

        $impersonatorId = $session->impersonatorUserId;
        if ($impersonatorId === null) {
            throw new ValidationException('Session is not impersonating');
        }

        // The identity being vacated, read before the rebind restores the administrator.
        $vacatedUserId = $session->userId;

        $this->rebindSession(new SessionRebindSignalData(
            sessionToken: $sessionToken,
            userId: $impersonatorId,
            impersonatorUserId: null,
            initiatorAcceptKey: $initiatorAcceptKey,
            correlationId: $correlationId,
        ));

        $this->logAgentInfo('impersonate_stop ' . json_encode([
            'event' => 'impersonate_stop',
            'admin' => $impersonatorId,
            'restoredUser' => $impersonatorId,
            'vacatedUser' => $vacatedUserId,
            'session' => $session->id,
        ]));
    }

    /**
     * Decides whether one user may take another over - the project's half of the
     * impersonation pair.
     *
     * A seam with a refusing default rather than an abstract method, the shape
     * {@see self::applyAdminGrant()} uses and for the same reason: the mount stands on this
     * class, so every project subclassing it offers the control whether or not it keeps a
     * privilege of its own to judge. The refusal reaches an operator as the command's error
     * reply and a browser as the action's fail ack.
     *
     * BOTH questions are the project's and both are answered by throwing: whether the asker
     * is privileged - the flag that says so is a project column no framework library can see
     * - and whether the target exists at all, since the users are the project's collection.
     * The framework asks them together because a caller allowed to take over a user it
     * cannot name has learned nothing, and one that may not is owed the same answer whatever
     * it named.
     *
     * @param int $adminUserId User the acting session currently carries
     * @param int $targetUserId User that session asks to act as
     * @throws NotImplementedException When the project has not wired the impersonation seam
     * @throws HilosException Whatever the project's implementation raises, an unknown user among it
     */
    protected function assertImpersonationAllowed(int $adminUserId, int $targetUserId): void
    {
        throw new NotImplementedException('Impersonation is not wired in this project');
    }

    /**
     * Runs {@see CliCommands::ACCOUNT_MERGE} for an operator: reads the two accounts off the
     * command payload, merges them, and answers the parked socket with what moved.
     *
     * The operator IS answered from here, unlike the impersonation pair: nothing about the
     * result reaches anybody else, so there is no later place that knows it better. What a
     * browser gets instead is {@see self::handleAccountMergeRequest()}.
     *
     * Every failure is caught, the project's seams among them: a command failure that reached
     * the worker loop would leave the operator parked on the socket with nothing coming back.
     *
     * @param CommandRequestDTO $data Command request carrying the survivor and loser user ids
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    private function handleAccountMergeCommand(CommandRequestDTO $data): void
    {
        // external-boundary: an operator's command line, refused by the guards below
        $survivorId = (int)($data->payload[AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID] ?? 0);
        $loserId = (int)($data->payload[AccountMergeCommandConstants::FIELD_LOSER_USER_ID] ?? 0);
        // An absent fate is the operator not naming one, which is a legitimate request and not
        // a missing field (HIL-692): the merge refuses on its own if the two accounts turn out
        // to need one. An unreadable value is the command's to reject before it sends, so both
        // absence and nonsense land on the same null and the guards below decide what it means.
        $namedFate = $data->payload[AccountMergeCommandConstants::FIELD_PASSWORD_FATE] ?? null;
        $passwordFate = is_string($namedFate) ? PasswordFate::tryFrom($namedFate) : null;

        try {
            $summary = $this->mergeAccounts($survivorId, $loserId, $passwordFate);
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, $summary->toArray()));
    }

    /**
     * Runs the merge a project's admin surface asked for, and hands the outcome back to it.
     *
     * The second way into one core (HIL-378, HIL-729). The person who asked is on a page of
     * the project's, waiting under an ack name only that project knows, so what goes back is
     * the outcome and their accept key - and the project says it out loud, exactly as it does
     * for a session state.
     *
     * No password fate travels with it and none is asked for: the admin surface has no
     * control that names one (HIL-411), so two accounts that each hold a password are refused
     * here and merged from a command line instead.
     *
     * @param AccountMergeSignalData $request Both accounts and the connection waiting on the answer
     * @throws InvalidArgumentException When the answering frame cannot be named
     */
    private function handleAccountMergeRequest(AccountMergeSignalData $request): void
    {
        try {
            $summary = $this->mergeAccounts($request->survivorUserId, $request->loserUserId, null);
        } catch (Throwable $e) {
            $this->answerAccountMerge(new AccountMergeResultSignalData($request->acceptKey, $e->getMessage()));

            return;
        }

        $this->answerAccountMerge(new AccountMergeResultSignalData($request->acceptKey, $summary));
    }

    /**
     * Sends one merge outcome back over the seam it arrived on.
     *
     * @param AccountMergeResultSignalData $result What the merge did, or why it did nothing
     * @throws InvalidArgumentException When the frame cannot be named
     */
    private function answerAccountMerge(AccountMergeResultSignalData $result): void
    {
        $this->sendToAgent(HilosSignalConstants::HILOS_ACCOUNT_MERGE_RESULT, $result);
    }

    /**
     * Folds one account into another and reports what moved - the core behind both ways in
     * (HIL-378).
     *
     * The survivor absorbs the loser's ways in and the rows the project keeps for it, then the
     * loser is tombstoned and its live sessions are signed out. It runs here rather than in a
     * project agent because of that last step: the sessions are this library's, and before
     * HIL-729 the merge had to ASK for each sign-out over a frame.
     *
     * Guards, in order: the two ids must differ, which is the one refusal that needs nobody's
     * help; then the project answers whether these two accounts may be merged at all
     * ({@see self::assertMergeable()}), because existence and a tombstone are its columns;
     * then the passwords are weighed, because the identities are the framework's. One guard
     * is about the merge rather than the ids (HIL-692): an account holds at most one password,
     * so two accounts that each have one cannot both be right and this refuses until the
     * operator says which stays. While at most one of the two has a password, that one
     * survives and the command keeps the shape it always had.
     *
     * The transfer is one explicit transaction so a half-merged account can never survive a
     * mid-way failure: the identity re-point and everything the project moves either all
     * commit or all roll back. Ordering inside it is free - the loser is tombstoned, never
     * deleted, so no foreign-key cascade can fire.
     *
     * What comes back is the OUTCOME and not the request: the account is asked afterwards
     * which password it now carries, so a fate naming an account that had none reports the
     * truth ({@see PasswordFate::NONE}) rather than the word that was typed.
     *
     * @param int $survivorId Survivor user id that absorbs the loser
     * @param int $loserId Loser user id folded into the survivor
     * @param ?PasswordFate $passwordFate Whose password to keep, or null when nobody named one
     * @return AccountMergeSummary Counts of what moved, and whose password the account kept
     * @throws ValidationException When a guard rejects the merge, the project's among them
     * @throws NotImplementedException When the project has not wired the merge seams
     * @throws InvalidArgumentException When a sign-out frame cannot be named
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws HilosException On database or truth-source failure (transaction rolled back)
     */
    private function mergeAccounts(int $survivorId, int $loserId, ?PasswordFate $passwordFate): AccountMergeSummary
    {
        if ($survivorId === $loserId) {
            throw new ValidationException('Cannot merge a user into itself');
        }

        $this->assertMergeable($survivorId, $loserId);

        if ($passwordFate === null && Hilos::$db->identities->passwordFateNeeded($loserId, $survivorId)) {
            throw new ValidationException('Both accounts have a password: pass --password=survivor|loser|none');
        }

        $survivorPasswordId = Hilos::$db->identities->findPasswordByUser($survivorId)?->id;

        Database::transactionStart();
        try {
            $identitiesMoved = Hilos::$db->identities->rePointToUser($loserId, $survivorId, $passwordFate);
            $rowsMoved = $this->applyAccountMerge($survivorId, $loserId);
            Database::transactionCommit();
        } catch (HilosException $e) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // A failing rollback would replace the error that made the merge fail
            }

            throw $e;
        }

        $keptPasswordId = Hilos::$db->identities->findPasswordByUser($survivorId)?->id;
        $passwordKept = match (true) {
            $keptPasswordId === null => PasswordFate::NONE,
            $keptPasswordId === $survivorPasswordId => PasswordFate::SURVIVOR,
            default => PasswordFate::LOSER,
        };

        $this->logAgentInfo('account_merge ' . json_encode([
            'event' => 'account_merge',
            'survivor' => $survivorId,
            'loser' => $loserId,
            AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED => $identitiesMoved,
            AccountMergeCommandConstants::FIELD_ROWS_MOVED => $rowsMoved,
            AccountMergeCommandConstants::FIELD_PASSWORD_KEPT => $passwordKept->value,
        ]));

        $this->killUserSessions($loserId);

        return new AccountMergeSummary($identitiesMoved, $rowsMoved, $passwordKept);
    }

    /**
     * Signs out every live session of a merged loser (HIL-378).
     *
     * A tombstoned loser must not keep acting through an open tab. Each of its sessions is
     * reverted to anonymous through {@see self::rebindSession()}, called rather than
     * signalled, because since HIL-729 the merge already runs in the process that owns them.
     * The loser is deactivated by the tombstone, so re-authentication is impossible.
     *
     * The impersonation marker is carried through unchanged rather than named null: a session
     * someone was impersonating through is being signed out, not un-impersonated.
     *
     * Runs outside the merge transaction: the transfer is already durable, and nothing here
     * may participate in the rollback path.
     *
     * @param int $loserId Merged loser user id whose sessions are closed
     * @throws InvalidArgumentException When a state frame cannot be named
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws HilosException When reading or reverting the loser's sessions fails
     */
    private function killUserSessions(int $loserId): void
    {
        foreach (Hilos::$db->sessions->findByUserId($loserId) as $session) {
            $this->rebindSession(new SessionRebindSignalData(
                sessionToken: $session->token,
                userId: null,
                impersonatorUserId: $session->impersonatorUserId,
            ));
        }
    }

    /**
     * Decides whether these two accounts may be merged at all - the project's first half of
     * the merge pair.
     *
     * A seam with a refusing default, the shape {@see self::assertImpersonationAllowed()}
     * uses and for the same reason: the mount stands on this class, so an operator who typed
     * the command into a project that wires nothing hears a refusal rather than silence.
     *
     * BOTH accounts are the project's to vouch for: whether a user id names anybody is
     * answered by its own collection, and so is whether that account has already been folded
     * into a third. The framework asks before it weighs the passwords, so an id that names
     * nobody is refused as such rather than as a password question.
     *
     * @param int $survivorUserId Survivor user id that would absorb the loser
     * @param int $loserUserId Loser user id that would be folded in
     * @throws NotImplementedException When the project has not wired the merge seams
     * @throws HilosException Whatever the project's implementation raises, an unknown user among it
     */
    protected function assertMergeable(int $survivorUserId, int $loserUserId): void
    {
        throw new NotImplementedException('Account merge is not wired in this project');
    }

    /**
     * Moves everything this project keeps for the loser onto the survivor - its second half.
     *
     * Called INSIDE the merge transaction, between the identity re-point and the commit, so
     * whatever it writes rolls back with the rest. The tombstone belongs here too: which row
     * marks an account as folded away is the project's column, and the framework only needs
     * the count of what travelled.
     *
     * The tally is a map rather than a number because the framework cannot know what a
     * project keeps for a person - in a chat, the messages. It goes back to the operator
     * under the project's own family names ({@see AccountMergeCommandConstants::FIELD_ROWS_MOVED}).
     *
     * @param int $survivorUserId Survivor user id that absorbs the loser
     * @param int $loserUserId Loser user id folded into the survivor
     * @return array<string, int> Rows re-pointed, per family this project names
     * @throws NotImplementedException When the project has not wired the merge seams
     * @throws HilosException Whatever the project's implementation raises while moving its rows
     */
    protected function applyAccountMerge(int $survivorUserId, int $loserUserId): array
    {
        throw new NotImplementedException('Account merge is not wired in this project');
    }

    /**
     * Rolls ONE browser's expired registration back to the identifier step (HIL-415).
     *
     * What an expired hold owes the person waiting on it. The step goes BACK rather than
     * the code being refused: they are about to type a code into a registration that no
     * longer exists, and "invalid code" would read as their mistake. The reason travels
     * with the step so the surface can say what actually happened.
     *
     * Only the owning session is rolled back (HIL-608). The identifier may still be held
     * by another browser whose own code is perfectly good, and taking that browser off its
     * code screen because a stranger's attempt ran out would be somebody else's timer
     * ending this person's registration.
     *
     * @param string $sessionToken Session cookie token whose hold just expired
     * @param string $identifier Normalized identifier that hold was on
     * @throws HilosException On runtime failure
     */
    private function rollBackRegistrationWaiters(string $sessionToken, string $identifier): void
    {
        // Read before the durable row goes, since the live sockets are found through it.
        $acceptKeys = $this->sessionConnectionKeys($sessionToken);
        foreach (Hilos::$rt->hilosRegistrationWaiters->forIdentifier($identifier) as $waiter) {
            if ($waiter->sessionToken === $sessionToken) {
                $acceptKeys[] = $waiter->acceptKey;
            }
        }

        // The durable memory goes next, still ahead of the first signal: the hold is
        // gone, so a tab reconnecting a second later must be told the identifier step by
        // the handshake, not parked again on a code screen this very sweep is closing.
        // Cleared on THIS session's row alone, not on every session waiting on the
        // address - the narrowing HIL-608 made, kept when the memory moved onto the row.
        Hilos::$db->sessions->findByToken($sessionToken)?->actions->releasePendingRegistration();

        foreach (array_unique($acceptKeys) as $acceptKey) {
            Hilos::$rt->hilosRegistrationWaiters->actions->release($acceptKey);

            $this->sendToUser(
                HilosSignalConstants::HILOS_AUTH_CONVERGE,
                $acceptKey,
                new AuthConvergeSignalData(
                    $acceptKey,
                    $identifier,
                    AuthFlowStep::IDENTIFIER,
                    AuthFlowIntent::REGISTER,
                    AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
                ),
            );
        }
    }

    /**
     * Drops registration waiters whose connection is no longer live (HIL-415).
     *
     * A waiter is released when its identifier resolves, and a browser closed on the code
     * screen never resolves anything - so without this the collection would only grow, and
     * a converge would be broadcast to sockets that are gone. The walk is over the waiters
     * and not over the connections, because there are at most a handful of the former and
     * as many of the latter as the chat has readers; while nobody is registering it costs
     * one count().
     *
     * @throws HilosException On runtime failure
     */
    private function sweepRegistrationWaiters(): void
    {
        if (count(Hilos::$rt->hilosRegistrationWaiters) === 0) {
            return;
        }

        // A project with no session-stage connections has nothing to compare a waiter
        // against, and dropping every one of them on that ignorance would be worse than
        // keeping them: the same nothing-to-do sessionConnectionKeys() answers with.
        $connections = Hilos::$rt?->sessionConnectionsSource();
        if ($connections === null) {
            return;
        }

        foreach (Hilos::$rt->hilosRegistrationWaiters as $waiter) {
            $acceptKey = $waiter->acceptKey;
            if ($connections->get($acceptKey) === null) {
                Hilos::$rt->hilosRegistrationWaiters->actions->release($acceptKey);
            }
        }
    }

    /**
     * Drops recovery waiters whose connection is no longer live (HIL-416).
     *
     * The same reclamation the registration waiters get, and needed for the same reason:
     * a browser closed on the code or password screen resolves nothing, so without this
     * the collection would only grow and a converge would be broadcast to sockets that
     * are gone. It is a second walk rather than a shared one because the two collections
     * are two: they are parked by different flows, and a sweep that knew about both
     * would have to be told which is which anyway.
     *
     * @throws HilosException On runtime failure
     */
    private function sweepRecoveryWaiters(): void
    {
        if (count(Hilos::$rt->hilosRecoveryWaiters) === 0) {
            return;
        }

        // A project with no session-stage connections has nothing to compare a waiter
        // against, and dropping every one of them on that ignorance would be worse than
        // keeping them: the same nothing-to-do sessionConnectionKeys() answers with.
        $connections = Hilos::$rt?->sessionConnectionsSource();
        if ($connections === null) {
            return;
        }

        foreach (Hilos::$rt->hilosRecoveryWaiters as $waiter) {
            $acceptKey = $waiter->acceptKey;
            if ($connections->get($acceptKey) === null) {
                Hilos::$rt->hilosRecoveryWaiters->actions->release($acceptKey);
            }
        }
    }

    /**
     * Signs one session in on the library's word and answers the surface that asked.
     *
     * The ending shared by every ceremony that proves who somebody is against an account
     * that already exists. The mark goes on the sockets BEFORE the sign-in, because the
     * surface closes on the identity coming up and would otherwise take the sentence with
     * it (HIL-422); the reply goes last, after the session is really up.
     *
     * @param AuthSessionGrantSignalData $frame Session, user, and the answer to give
     * @throws HilosException On database, runtime, or session failure
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws InvalidArgumentException When the reply frame cannot be named
     */
    private function grantSessionToUser(AuthSessionGrantSignalData $frame): void
    {
        $this->authenticateSession(
            $frame->sessionToken,
            $frame->userId,
            $frame->acceptKey,
            ack: $frame->ack,
            requestId: $frame->requestId,
            action: $frame->action,
            outcome: $frame->outcome,
        );
    }

    /**
     * Ends a registration for the browser that won it and for the ones that were racing it.
     *
     * The order is the mechanism (HIL-415, HIL-422): mark the winner's sockets, raise its
     * session, drop the wait of the socket that submitted the proof - its caller is
     * answered by the reply below rather than by a converge - and only then settle every
     * other surface standing on the identifier.
     *
     * @param AuthRegistrationLandedSignalData $frame Identifier, account, winner, and losers
     * @throws HilosException On runtime, database, or session failure
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws InvalidArgumentException When a converge or reply frame cannot be named
     */
    private function settleLandedRegistration(AuthRegistrationLandedSignalData $frame): void
    {
        // The mark rides the sign-in rather than going ahead of it: the surface closes on
        // the session coming up, so the two have to reach the browser together (HIL-422).
        $this->authenticateSession(
            $frame->winnerSessionToken,
            $frame->userId,
            $frame->initiatorAcceptKey,
            ack: SessionAck::REGISTERED,
            requestId: $frame->requestId,
            action: $frame->action,
            outcome: $frame->outcome,
        );

        Hilos::$rt->hilosRegistrationWaiters->actions->release($frame->initiatorAcceptKey);
        $this->convergeRegistration(
            $frame->identifier,
            $frame->userId,
            $frame->initiatorAcceptKey,
            $frame->winnerSessionToken,
            $frame->losingSessionTokens,
        );
    }

    /**
     * Opens the password step for a browser whose recovery code was just accepted.
     *
     * Four steps, and the order is the mechanism (HIL-416, HIL-685). The initiator's row
     * is made to say THIS address first - a browser that reconnected between asking for
     * the code and proving it lost the row its grant is written on, and one whose
     * wait-moved frame never arrived has a row naming the address it walked away from.
     * Re-pointing rather than parking is what makes this handler answer for itself: the
     * frame that carries a moved wait is best-effort, and the grant below is written by
     * matching that very address, so a stale row would silently un-grant the person who
     * just proved a code. Then the grant itself is written - on every row of this session
     * standing on this address, and taken off every row of it standing on another, since a
     * session is on one recovery at a time. Then the neighbouring tabs are moved onto the
     * password step. The answer to the tab that submitted goes LAST, because it is what
     * opens that step, and a grant that arrived after it would be a password screen with
     * nothing behind it.
     *
     * The grant moved here from the library with the write it needs (HIL-685): it edits
     * rows, the library may only add and remove them, and the holder was already touching
     * these very rows one line below.
     *
     * @param AuthRecoveryGrantedSignalData $frame Address, session, and the answer to give
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When a converge or reply frame cannot be named
     */
    private function openRecoveryPasswordStep(AuthRecoveryGrantedSignalData $frame): void
    {
        Hilos::$rt->hilosRecoveryWaiters->actions->repoint(
            $frame->initiatorAcceptKey,
            $frame->identifier,
            $frame->sessionToken,
        );
        Hilos::$rt->hilosRecoveryWaiters->actions->acceptCodeForSession($frame->sessionToken, $frame->identifier);
        $this->grantRecoveryToSession($frame->identifier, $frame->sessionToken, $frame->initiatorAcceptKey);
        $this->answerLibraryAction(
            $frame->initiatorAcceptKey,
            $frame->sessionToken,
            $frame->action,
            $frame->requestId,
            $frame->outcome,
        );
    }

    /**
     * Returns an account to the browser that reset its password, and takes it from everyone else.
     *
     * A reset happens when access has leaked, so it ends with one live session and not with
     * one more: the saving browser is signed in, the surfaces waiting on the address are
     * settled, and every OTHER session of the account is dropped to anonymous. The session
     * that stays is named by the token it holds NOW - the sign-in above rotated it, and the
     * frame carries the one the browser presented before that (HIL-582). The rotated name
     * comes back from the sign-in itself rather than being read off the connection row: the
     * row is re-pointed by the project on the frame that has only just left here, so asking
     * it would answer with the token the session no longer has (HIL-710).
     *
     * @param AuthPasswordChangedSignalData $frame Account, session, address, and the answer to give
     * @throws HilosException On database, runtime, or session failure
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws InvalidArgumentException When a converge or reply frame cannot be named
     */
    private function settleChangedPassword(AuthPasswordChangedSignalData $frame): void
    {
        // The mark rides the sign-in rather than going ahead of it: the surface closes on
        // the session coming up, so the two have to reach the browser together (HIL-422).
        $liveToken = $this->authenticateSession(
            $frame->sessionToken,
            $frame->userId,
            $frame->acceptKey,
            ack: SessionAck::PASSWORD_CHANGED,
            requestId: $frame->requestId,
            action: $frame->action,
            outcome: $frame->outcome,
        );
        $this->convergeRecovery($frame->identifier, $frame->sessionToken, $frame->acceptKey);
        $this->deauthenticateOtherSessions($frame->userId, $liveToken ?? $frame->sessionToken);
    }

    /**
     * Forgets the registration one browser walked away from, in every tab of it.
     *
     * @param AuthRegistrationAbandonedSignalData $frame Session that walked away and the answer to give
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When a converge or reply frame cannot be named
     */
    private function dropAbandonedRegistration(AuthRegistrationAbandonedSignalData $frame): void
    {
        $this->abandonRegistration($frame->sessionToken, $frame->initiatorAcceptKey);
        $this->answerLibraryAction(
            $frame->initiatorAcceptKey,
            $frame->sessionToken,
            $frame->action,
            $frame->requestId,
            $frame->outcome,
        );
    }

    /**
     * Makes one registration waiter say the address its browser is on now (HIL-685).
     *
     * The edit half of parking, which the users library may not do: it adds rows to this
     * collection and removes them, and the row of a browser that submitted a second
     * address is already there. So the library parks what is missing, sends this, and the
     * two together are the upsert that used to sit inside `park()`.
     *
     * Nothing is answered. The browser that asked was told its code went out by the
     * library itself, because that answer never stood on the wait: the row is what a
     * converge reaches the OTHER tabs of the session through.
     *
     * @param AuthRegistrationWaitMovedSignalData $frame Connection, address, and session it waits on now
     * @throws HilosException On runtime failure
     */
    private function moveRegistrationWait(AuthRegistrationWaitMovedSignalData $frame): void
    {
        Hilos::$rt->hilosRegistrationWaiters->actions->repoint(
            $frame->acceptKey,
            $frame->identifier,
            $frame->sessionToken,
        );
    }

    /**
     * Makes one recovery waiter say the address its browser is on now, un-granted (HIL-685).
     *
     * The recovery twin of {@see moveRegistrationWait()}, and it takes one thing more away:
     * re-pointing clears the grant, because a proven code buys the password step of the
     * address it was proven for and of no other. A person who asks for a second code from
     * the same tab has left the first address, and the grant does not follow them.
     *
     * @param AuthRecoveryWaitMovedSignalData $frame Connection, address, and session it recovers now
     * @throws HilosException On runtime failure
     */
    private function moveRecoveryWait(AuthRecoveryWaitMovedSignalData $frame): void
    {
        Hilos::$rt->hilosRecoveryWaiters->actions->repoint(
            $frame->acceptKey,
            $frame->identifier,
            $frame->sessionToken,
        );
    }

    /**
     * Answers the sign-in action a frame finished, when there is a caller waiting on it.
     *
     * The users library deferred its own reply so that the answer would leave from behind
     * the identity it announces (HIL-622), and since HIL-710 that identity is sent by the
     * project - so the answer travels there too, on a frame that states what the session is
     * and names the one socket that asked. Nothing is sent when the frame carries no
     * request id: the session grant is also the ending of an OAuth login, whose action was
     * acked as "accepted, working on it" the moment the browser was sent to the provider.
     *
     * The frame restates an identity that has not changed, which is the price of having one
     * frame rather than two: the alternative is a second kind of frame whose only content
     * is an answer, and a project reading it would have to know which of the two it holds.
     *
     * @param string $acceptKey Accept key of the connection that submitted the action
     * @param string $sessionToken Session cookie token the answering socket belongs to
     * @param ?string $action Action name the frame finished, or null when it finished none
     * @param ?string $requestId Request id of the waiting caller, or null when nobody waits
     * @param ?array<string, mixed> $outcome Where the surface goes next, or null for no domain reply
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws HilosException When the session or its unfinished registration cannot be read
     */
    private function answerLibraryAction(
        string $acceptKey,
        string $sessionToken,
        ?string $action,
        ?string $requestId,
        ?array $outcome,
    ): void {
        if ($action === null || $requestId === null) {
            return;
        }

        $session = Hilos::$db->sessions->findByToken($sessionToken);
        $this->publishSessionState(new SessionStateSignalData(
            sessionToken: $sessionToken,
            userId: $session?->userId,
            acceptKeys: [$acceptKey],
            pendingAck: $this->connectionPendingAck($acceptKey),
            pendingAuthStep: $this->pendingAuthStepFor($session),
            requestId: $requestId,
            action: $action,
            outcome: $outcome,
        ));
    }

    /**
     * Reads the connections waiting on one identifier before any of them is released.
     *
     * The list is materialized first on purpose: releasing a waiter mutates the collection
     * a foreach over it would be walking, and the session token has to be read while the
     * row is still there.
     *
     * TWO sources, because the runtime list is a projection and not the truth (HIL-486):
     * a socket is parked when its session handshakes, so the socket that ASKED for the
     * code is not in it - it has not handshaken since. The session rows close that gap;
     * they name the sessions waiting on the address, and every live socket of such a
     * session is waiting whether or not it was ever parked. Sessions with no live socket
     * contribute nothing and cost nothing: their tabs are told at the handshake, by the
     * step the response carries.
     *
     * @param string $identifier Normalized identifier being converged
     * @return array<string, string> Session token by waiting connection accept key
     * @throws HilosException On runtime failure
     */
    private function parkedAcceptKeys(string $identifier): array
    {
        $parked = [];
        foreach (Hilos::$rt->hilosRegistrationWaiters->forIdentifier($identifier) as $waiter) {
            $parked[$waiter->acceptKey] = $waiter->sessionToken;
        }

        foreach (Hilos::$db->sessions->findAwaitingRegistration($identifier) as $session) {
            foreach ($this->sessionConnectionKeys($session->token) as $acceptKey) {
                $parked[$acceptKey] = $session->token;
            }
        }

        return $parked;
    }

    /**
     * Reads the connections parked on one recovery before any of them is released.
     *
     * The list is materialized first on purpose: releasing a waiter mutates the collection
     * a foreach over it would be walking, and the session token has to be read while the
     * row is still there - it is what tells the saver's own tabs from another device's.
     *
     * @param string $identifier Normalized identifier being converged
     * @return array<string, string> Session token by waiting connection accept key
     * @throws HilosException On runtime failure
     */
    private function parkedRecoveryAcceptKeys(string $identifier): array
    {
        $parked = [];
        foreach (Hilos::$rt->hilosRecoveryWaiters->forIdentifier($identifier) as $waiter) {
            $parked[$waiter->acceptKey] = $waiter->sessionToken;
        }

        return $parked;
    }
}
