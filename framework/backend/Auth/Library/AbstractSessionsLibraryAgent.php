<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationAbandonedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Registration\RegistrationReservationSweeper;
use Hilos\Auth\Session\DTO\DismissSessionAckActionDTO;
use Hilos\Auth\Session\DTO\LogoutActionDTO;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\Exception\SessionTokenExhaustedException;
use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\Session\SessionRebindConstants;
use Hilos\Auth\Session\SessionRotationTicket;
use Hilos\Auth\Session\SessionToken;
use Hilos\Auth\Throttle\ThrottleGate;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\NotImplementedException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\View\Item\Session;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Users\AdminCommandConstants;
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
     * The frames this library is addressed by: seven from the users library, one from the
     * project holding the sockets.
     *
     * Routing takes the destination from whoever declares a name here, so this list IS the
     * move: the frames the users library has always sent to "the holder" now arrive at an
     * agent of the framework's own, and nothing about their senders changed (HIL-710).
     * {@see HilosSignalConstants::HILOS_SESSION_STATE} is deliberately absent - it is the
     * frame this library SENDS, and the project declares it.
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
    ];

    /**
     * The two page-independent controls of the sign-in surface, by wire name.
     *
     * Both resolve their session from the ACTING connection and take no payload, so a
     * client can only ever end or dismiss its own. They are the library's rather than a
     * project's because what they write is a session (HIL-710); the controls that judge a
     * project field - stopping an impersonation, for one - stay where that field is.
     */
    public const array AGENT_ACTIONS = [
        HilosSignalConstants::HILOS_LOGOUT => LogoutActionDTO::class,
        HilosSignalConstants::HILOS_DISMISS_SESSION_ACK => DismissSessionAckActionDTO::class,
    ];

    /**
     * The operator path to the first administrator (HIL-609), mounted here because the
     * command ends in a session bind and sessions are this library's.
     *
     * Every project that registers the library answers this command, and one that mints no
     * users answers a REFUSAL from {@see ensureAdminUser()} rather than nothing at all. That
     * is the honest outcome for an operator who typed it into the wrong installation: before
     * the move the name was carried by whichever agent chose to, so a project that did not
     * left the command socket silent - which reads as a hang, not as a no.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::ADMIN_CREATE,
    ];

    /** @var int Times a rotation re-mints a token another session already holds before giving up */
    private const int TOKEN_MINT_ATTEMPTS = 3;

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

    /** @var ?CronRule Schedule of the abandoned-registration sweep, or null when it is switched off */
    private ?CronRule $pendingRegistrationSweepRule = null;

    /** @var ?CronRule Schedule of the expired-hold sweep, or null when this project has no registration */
    private ?CronRule $reservationSweepRule = null;

    /**
     * Claims the session set and arms the two sweeps that keep it honest.
     *
     * The session set and the rotations are claimed OUTRIGHT: a session belongs to this
     * library whole. Nothing may write a framework collection until somebody says who owns
     * it, so a project that never registers this agent simply has no sessions rather than
     * sessions changing in a process no one else hears.
     *
     * The two WAIT collections are claimed only where they exist. They are mounted by
     * {@see AuthFeature::mount()} and by nothing else, so in a project with no sign-in
     * surface there is no collection to own - and a library that claimed one anyway would
     * go on to read it every tick and raise on every pass. The users library stands beside
     * this one on both as a declared add/remove co-owner (HIL-685) rather than as a second
     * full owner, and it is only ever registered where the feature is.
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
        if ($this->hasSignInSurface()) {
            $this->registerRtTruthSource(StateRegistrationWaiter::RT_COLLECTION);
            $this->registerRtTruthSource(StateRecoveryWaiter::RT_COLLECTION);
        }

        $this->armPendingRegistrationSweep();
        $this->armReservationSweep();
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
     * Everything but the rotations is behind the sign-in surface, because everything but the
     * rotations is a row the surface makes. A project without one reaches this method just
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
        $this->parkPendingRegistration($data->acceptKey, $session);

        $this->publishSessionState(new SessionStateSignalData(
            sessionToken: $session->token,
            userId: $session->userId,
            acceptKeys: [$data->acceptKey],
            pendingAck: $data->inheritedAck,
            pendingRegistration: $this->pendingRegistrationFor($session),
        ));
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
     * clears one walks every socket of the session in one pass. The one way they diverge is
     * a socket that inherited an ack through a rotation while another tab reconnected
     * without one, and the frame that restates the session's identity carries the
     * announcement rather than dropping it.
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
     * Describes the registration a session started and has not finished (HIL-486).
     *
     * The step the surface comes back to, served from the server rather than kept in
     * the tab: a reload, a second tab and another device all ask the same question at
     * their handshake and get the same answer. A session holding no live reservation
     * answers null - its registration completed or ran out, and the person belongs on
     * the identifier field, not on a code screen for a code that can no longer be
     * confirmed.
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
     * @return ?array{identifier: string, kind: string, channel: ?string, expiresAt: int} Step, or null when there is none
     * @throws HilosException When the reservation or verification query fails
     */
    private function pendingRegistrationFor(?Session $session): ?array
    {
        if ($session === null) {
            return null;
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
            HandshakeResponseSignalData::channel => $kind === IdentifierDetection::KIND_PHONE
                ? new VerificationService()->activeChannel(VerificationType::SMS_LOGIN, $identifier)
                : null,
            HandshakeResponseSignalData::expiresAt => TimeHelper::sqlToMs($reservation->expiresAt),
        ];
    }

    /**
     * Parks a connection on the registration its session left unfinished (HIL-486).
     *
     * The runtime waiter list stops being state of its own and becomes a projection
     * of the durable wait: a socket that opens into a session with a wait joins the
     * converge broadcast without having submitted anything itself. That is what makes
     * a second tab react as though the code had been typed in it - which the flow
     * requires, and which no amount of per-connection memory could give it, because
     * the connection asking is new.
     *
     * Called by the project's handshake handler, which is the only place holding both
     * the accept key and the moment: the response builder above is also used for
     * broadcasts to sockets that are already parked or deliberately are not.
     *
     * @param string $acceptKey Accept key of the connection that just handshook
     * @param ?Session $session Session the connection resolved to, or null when it has none
     * @throws HilosException When the runtime write fails
     */
    private function parkPendingRegistration(string $acceptKey, ?Session $session): void
    {
        if ($session === null) {
            return;
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
     * session onto that user. The four outcomes an operator can meet - a session with no
     * user, a session carrying a visitor, a session that is already an administrator, a
     * token naming no session - fall out of that one path rather than out of four branches,
     * which would be four places to forget the re-point that makes the grant visible.
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
     * The session is resolved with a plain lookup rather than through
     * {@see self::resolveHandshakeSession()}, so the HIL-398 expiry downgrade does NOT run
     * here and an expired session named by an operator is re-bound and slid forward rather
     * than refused. That is deliberate and of a piece with the block flag this command also
     * does not consult: those guards judge a BROWSER presenting a cookie by itself, and this
     * is an operator naming a session on purpose. It grants nothing extra either - whoever
     * reaches this unauthenticated socket can already {@see CliCommands::ADMIN_GRANT} any
     * user id. In the walked operator path it cannot even arise: the browser handshakes
     * first, so an expired session has already been downgraded to anonymous and arrives here
     * carrying no user at all.
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

            $created = $session->userId === null;
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
        ]));
    }

    /**
     * Makes one user an administrator, minting the row when there is no user yet - the
     * project's half of {@see self::handleAdminCreateCommand()}.
     *
     * A seam with a refusing default rather than an abstract method, the shape
     * {@see AbstractHilosIndexAgent::applyAdminGrant()} uses: {@see self::AGENT_COMMANDS}
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
                pendingRegistration: $this->pendingRegistrationFor($session),
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
            pendingRegistration: $this->pendingRegistrationFor($session),
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
            pendingRegistration: $this->pendingRegistrationFor($session),
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
     * A waiter whose connection is ALREADY signed in is moved but not re-bound: somebody
     * else's registration must never swap the account a person is sitting in. The
     * confirming connection is skipped entirely - its caller signed it in on the ordinary
     * path and answered it with the action reply.
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
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
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

            if (Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->userId === null) {
                // Marked before the session goes up, so the identity and the news that
                // there is an account arrive in one frame (HIL-422). This tab did not
                // type the code — another tab of the same browser did — which is exactly
                // why it is owed the sentence rather than a screen that changed under it.
                $this->authenticateSession($sessionToken, $userId, $acceptKey, ack: SessionAck::REGISTERED);
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
     * Routes one frame addressed to this library - seven from the users library, one back
     * over the project seam (HIL-622, HIL-710).
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

            default:
                throw new AgentUnknownSignalException($name);
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
     * Runs one of the two page-independent controls of the sign-in surface.
     *
     * Both take their session from the ACTING connection, read off the project's own
     * connection rows - which this library may read but never write. A stale accept key or
     * a connection belonging to no session is a no-op, because there is nothing to end or
     * dismiss and refusing would only tell a closed tab about it.
     *
     * Neither answers here. The ending is a session state, which the project puts on the
     * wire, so the answer is carried in that frame and leaves behind the identity it
     * announces (HIL-622).
     *
     * @param string $acceptKey Accept key of the connection that submitted
     * @param string $action Owned action name from {@see AGENT_ACTIONS}
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the project answers on the state frame instead
     * @throws AgentUnknownActionException When the action is not one this library owns
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws InvalidArgumentException When the state frame cannot be named
     * @throws HilosException When ending the session exposes database or runtime failure
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        $sessionToken = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->sessionToken;
        switch ($action) {
            case HilosSignalConstants::HILOS_LOGOUT:
                if (!$dto instanceof LogoutActionDTO) {
                    throw new InvalidActionPayloadException($action, LogoutActionDTO::class, $dto);
                }
                if ($sessionToken !== null && $sessionToken !== '') {
                    $this->deauthenticateSession($sessionToken, $this->currentActionRequestId(), $action);
                }

                return null;

            case HilosSignalConstants::HILOS_DISMISS_SESSION_ACK:
                if (!$dto instanceof DismissSessionAckActionDTO) {
                    throw new InvalidActionPayloadException($action, DismissSessionAckActionDTO::class, $dto);
                }
                if ($sessionToken !== null && $sessionToken !== '') {
                    $this->clearSessionAck($sessionToken, $this->currentActionRequestId(), $action);
                }

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Routes a CLI command sent to this library.
     *
     * {@see CliCommands::ADMIN_CREATE} is the only one it mounts; anything else gets an
     * error reply rather than silence, because the socket parks the caller until it is
     * answered.
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

        $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
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
            pendingRegistration: $this->pendingRegistrationFor($session),
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
