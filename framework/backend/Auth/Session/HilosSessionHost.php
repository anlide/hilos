<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationAbandonedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\Exception\SessionTokenExhaustedException;
use Hilos\Auth\Throttle\ThrottleGate;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\NotImplementedException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\View\Item\Session;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Users\AdminCommandConstants;
use Hilos\Utils\Helpers\TimeHelper;
use Random\RandomException;
use Throwable;

/**
 * Session-host seam graduated from the chat reference (HIL-361).
 *
 * Mixed into a project's monopolistic agent (an {@see AbstractAgent}
 * subclass, whose `sendToUser()`/`logAgentInfo()`/`sendActionSuccess()` this trait calls) to own the
 * three session-lifecycle cores that used to live inline in the chat agent:
 * resolving a handshake token to a session, upgrading a live session to a user
 * (login/register), and reverting it to anonymous (logout). Each core drives the
 * framework-owned session ORM and re-points the session's live connections, then
 * delegates the project-specific parts — building the identity handshake payload,
 * writing one connection's bound user, and the emitted signal name — through the
 * abstract hooks below. Finding the connections of a token is no longer among them
 * (HIL-509): the rows stand on a framework base whose session stage carries the
 * token, so the seam locates them by type.
 *
 * A session is anonymous (user id null) until {@see authenticateSession()} binds a
 * user; {@see deauthenticateSession()} is the symmetric downgrade that keeps the
 * session row and token alive. The session-expiry drop (HIL-398) is enforced in
 * {@see resolveHandshakeSession()}: a cookie that resolves to an authenticated but
 * expired session is downgraded to anonymous before it is handed back, so a stale
 * cookie can never resume an authenticated identity.
 */
trait HilosSessionHost
{
    /** @var int Times a rotation re-mints a token another session already holds before giving up */
    private const int TOKEN_MINT_ATTEMPTS = 3;

    /** Name of the cron rule that clears abandoned registrations off session rows. */
    private const string PENDING_REGISTRATION_SWEEP_RULE = 'hilos_sweep_pending_registrations';

    /** @var ?CronRule Schedule of the abandoned-registration sweep, or null when it is switched off */
    private ?CronRule $pendingRegistrationSweepRule = null;

    /**
     * Claims the rotation store for this agent and clears anything the last process left.
     *
     * Called from the owning agent's `onStart()`. The store is a framework-owned collection
     * mounted for every project, but nothing may write it until somebody says who owns it -
     * so a project whose agent never calls this simply cannot rotate, rather than rotating
     * into state no other worker sees.
     */
    final protected function startSessionRotations(): void
    {
        $this->registerRtTruthSource(StateHilosSessionRotation::RT_COLLECTION);
    }

    /**
     * Arms the schedule of the abandoned-registration sweep (HIL-612).
     *
     * Called from the owning agent's `onStart()`. The rule is held and ticked by the
     * AGENT rather than routed through the daemon's cron: a project like tasks
     * names no cron-owning agent at all, and a rule that signalled would be shouting
     * into a socket nobody is holding.
     *
     * An empty expression arms nothing, which is the whole switch a project needs -
     * one that never registers anybody sweeps nothing and pays no tick for it.
     *
     * @throws EnvException When the schedule key is missing, outside the catalog, or of the wrong type
     */
    final protected function startPendingRegistrationSweep(): void
    {
        $expression = Hilos::$env?->string(EnvConstants::HILOS_PENDING_REGISTRATION_SWEEP_CRON);
        if ($expression === null || trim($expression) === '') {
            return;
        }

        $this->pendingRegistrationSweepRule = new CronRule(self::PENDING_REGISTRATION_SWEEP_RULE, $expression);
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
    final protected function sweepPendingRegistrations(): void
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
    final protected function sweepSessionRotations(): void
    {
        Hilos::$rt?->hilosSessionRotations->actions->forgetExpired();
    }

    /**
     * Builds the identity handshake response describing a session's current user.
     *
     * Project-owned because the display names come from the project's own user
     * store (and, while impersonating, the admin behind the takeover). An anonymous
     * or missing session yields the anonymous response that clears the frontend
     * current user.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return HandshakeResponseSignalData Handshake response for the session
     */
    abstract protected function handshakeResponseFor(?Session $session): HandshakeResponseSignalData;

    /**
     * Builds the handshake response a socket is actually sent (HIL-486).
     *
     * The framework half of the project's {@see handshakeResponseFor()} hook: the
     * project answers who the session is, this stamps on what the project has no way
     * to know — the server clock the browser measures its own offset against. Every
     * send path goes through here rather than through the hook, so no project can
     * ship a response without it: the field is framework-owned and identical
     * everywhere, while the sites that send one are five and two of them sit in the
     * project's own agent.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return HandshakeResponseSignalData Handshake response carrying the session context
     */
    final protected function handshakeResponse(?Session $session): HandshakeResponseSignalData
    {
        return $this->handshakeResponseFor($session)
            ->withSessionContext(TimeHelper::nowMs(), $this->pendingRegistrationFor($session));
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
    final protected function parkPendingRegistration(string $acceptKey, ?Session $session): void
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
    final protected function sessionConnectionKeys(string $sessionToken): array
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
    final protected function connectionPendingAck(string $acceptKey): ?string
    {
        return Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->pendingAck;
    }

    /**
     * Hands a freshly registered connection the ack its rotation carried over (HIL-423).
     *
     * Called from the project's handshake handler, right after the connection row exists
     * and before the response goes out; the value it returns is what that response has to
     * state. Only a handshake that spent a rotation ticket carries one, which is the whole
     * of the narrowing this leaf makes to HIL-422's rule: an ack still lives on the
     * connection and a bare reopened socket still owes nothing, but the socket that
     * replaces the one a login rotated away is the same browser mid-flow, not a reload,
     * and it inherits what its predecessor had not shown yet.
     *
     * @param WebSocketHandshakeSignalDTO $data Handshake signal the daemon queued for this connection
     * @return ?string Ack now standing on the connection, or null when there was none to inherit
     */
    final protected function inheritHandshakeAck(WebSocketHandshakeSignalDTO $data): ?string
    {
        $ack = $data->inheritedAck;
        if ($ack === null) {
            return null;
        }

        $this->markConnectionAck($data->acceptKey, $ack);

        return $ack;
    }

    /**
     * Re-points one live connection's bound user through the project runtime registry.
     *
     * Project-owned because the write goes through the project's per-connection
     * runtime actions. Called for every connection of a session whose bound user
     * changed, so a re-emitted handshake and the connection's own user stay in sync.
     *
     * @param string $acceptKey Connection accept key to re-point
     * @param ?int $userId User id to bind the connection to, or null for anonymous
     */
    abstract protected function bindConnectionUser(string $acceptKey, ?int $userId): void;

    /**
     * Writes one live connection's pending success ack through the project runtime registry.
     *
     * Project-owned for the same reason {@see bindConnectionUser()} is: the write goes
     * through the project's own per-connection runtime actions, which the framework
     * cannot name. The read side needs no hook — the row stands on a framework base,
     * so {@see connectionPendingAck()} finds the value by type.
     *
     * @param string $acceptKey Connection accept key to mark
     * @param ?string $ack Ack the connection owes (a {@see SessionAck} value), or null to clear it
     */
    abstract protected function markConnectionAck(string $acceptKey, ?string $ack): void;

    /**
     * Returns the signal name the project emits the handshake response under.
     *
     * The response DTO is framework-owned, but its signal name stays project-owned
     * (the frontend routes on the project constant).
     *
     * @return string Project handshake-response signal name
     */
    abstract protected function handshakeResponseSignalName(): string;

    /**
     * Project hook run after a session is bound to a user.
     *
     * Default no-op. A project overrides it to ensure per-user runtime state (e.g.
     * presence) for the newly authenticated user.
     *
     * @param int $userId Durable user id the session was bound to
     */
    protected function afterAuthenticate(int $userId): void
    {
    }

    /**
     * Project hook run after a session is reverted to anonymous.
     *
     * Default no-op. Presence normally follows the connection re-point, so most
     * projects need nothing here; a project overrides it for any de-identify work.
     *
     * @param int $userId Durable user id the session was unbound from
     */
    protected function afterDeauthenticate(int $userId): void
    {
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
    final protected function handleAdminCreateCommand(CommandRequestDTO $data): void
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
     * {@see AbstractHilosIndexAgent::applyAdminGrant()} uses: this trait is mixed into every
     * session host, and an abstract method would break the projects that host sessions
     * without ever mounting the command - the chat demo, which has a login of its own, is
     * one. The refusal reaches the operator as the command's error reply.
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
    public function resolveHandshakeSession(string $sessionToken): Session
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
     * The rotation is announced but not delivered here. The new token reaches the
     * browser through the master's Set-Cookie on the next handshake, traded for the
     * one-time ticket this method sends; see {@see SessionRotationTicket}.
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
     * @param string $sessionToken Session cookie token to authenticate
     * @param int $userId Durable user id to bind the session to
     * @param ?string $initiatorAcceptKey Accept key of the connection that logged in, or null when there is none
     * @throws HilosException On database or runtime failure
     * @throws RandomException When the platform's secure random source refuses a mint
     * @throws SessionTokenExhaustedException When three minted tokens in a row were already taken
     */
    public function authenticateSession(string $sessionToken, int $userId, ?string $initiatorAcceptKey): void
    {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            return;
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
        // has to be said out loud - and said HERE, above the handshake response built
        // below, which would otherwise hand the freshly authenticated browser back the
        // code screen it just left.
        $session->actions->releasePendingRegistration();

        $this->afterAuthenticate($userId);
        if ($rotated !== null) {
            // Analytics names a browser session by the token, not by the session row, so
            // the rotation has to be told - exactly as the runtime connection rows are.
            // Without it the visit before the login stays under a token nobody presents
            // again, and the identify below opens a second session for the same person.
            Hilos::$ac?->renameBrowserSession($sessionToken, $rotated);
        }
        Hilos::$ac?->identifyBrowserSessionUser($liveToken, $userId);

        $response = $this->handshakeResponse($session);
        $signalName = $this->handshakeResponseSignalName();

        if ($rotated === null) {
            // Nothing was rotated, so the session still answers to the token every one of
            // its connections named, and every one of them still belongs to it. Re-point
            // them all, exactly as this seam always did: the caller with no initiator is
            // acting on somebody else's session (the impersonation CLI), and the tabs of
            // that session have to learn who they are now.
            foreach ($this->sessionConnectionKeys($sessionToken) as $acceptKey) {
                $this->bindConnectionUser($acceptKey, $userId);
                $this->sendToUser($signalName, $acceptKey, $response->withPendingAck(
                    $this->connectionPendingAck($acceptKey),
                ));
            }

            return;
        }

        // The session's other connections are left anonymous on purpose: they are dropped
        // once the browser holds the new cookie, and they come back into the rotated
        // session by themselves. Authenticating them here is the second half of the attack
        // this leaf closes - a socket opened with a planted token would ride the victim's
        // login into her account.
        $keysToDrop = array_values(array_filter(
            $this->sessionConnectionKeys($sessionToken),
            static fn(string $acceptKey): bool => $acceptKey !== $initiatorAcceptKey,
        ));

        $this->repointInitiatorSessionToken($initiatorAcceptKey, $rotated);
        $this->bindConnectionUser($initiatorAcceptKey, $userId);
        $this->sendToUser($signalName, $initiatorAcceptKey, $response->withPendingAck(
            $this->connectionPendingAck($initiatorAcceptKey),
        ));

        $this->announceRotation($rotated, $keysToDrop, $initiatorAcceptKey);
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
     * Re-points the initiating connection's runtime row onto the rotated token.
     *
     * @param string $acceptKey Accept key of the connection that logged in
     * @param string $newToken Token the session was rotated onto
     * @throws HilosException On runtime failure
     */
    private function repointInitiatorSessionToken(string $acceptKey, string $newToken): void
    {
        Hilos::$rt?->sessionConnectionsRegistry()?->actions->repointSessionToken($acceptKey, $newToken);
    }

    /**
     * Announces the pending rotation and hands its ticket to the initiating connection.
     *
     * Order matters and is the mechanism, not a detail: the row has to exist before the
     * ticket is on the wire, or a browser fast enough to reconnect first would present a
     * ticket the master cannot find and lose the session.
     *
     * The initiator's pending ack rides on the row (HIL-423). The rotation ends the very
     * connection the ack was written on, so without this the sentence a flow just earned
     * dies with the socket that earned it, and the surface closes on a person who never
     * read it. The ticket is the one thing that says "the same browser, still in the flow
     * it started" — which is why the ack travels with it and not with the token.
     *
     * @param string $newToken Token the session was rotated onto
     * @param list<string> $keysToDrop Accept keys of the session's other connections
     * @param string $initiatorAcceptKey Accept key of the connection that logged in
     * @throws HilosException On runtime failure
     * @throws RandomException When the platform's secure random source refuses a mint
     */
    private function announceRotation(string $newToken, array $keysToDrop, string $initiatorAcceptKey): void
    {
        $ticket = SessionRotationTicket::mint();
        Hilos::$rt?->hilosSessionRotations->actions->register(
            $ticket,
            $newToken,
            $keysToDrop,
            SessionRotationTicket::expiryFromNow(),
            $this->connectionPendingAck($initiatorAcceptKey),
        );

        $this->sendToUser(
            HilosSignalConstants::HILOS_SESSION_ROTATE,
            $initiatorAcceptKey,
            new SessionRotateSignalData($ticket),
        );
    }

    /**
     * Reverts a live session to anonymous: nulls the session user, re-points the
     * session's active connections to no user, and re-emits the anonymous handshake
     * response so their frontends clear the current user. The inverse of
     * {@see authenticateSession()}.
     *
     * The session row and token are kept — the session simply becomes anonymous
     * again. A no-op when the token has no session row or is already anonymous.
     * Presence follows the connection re-point: a user with no other authenticated
     * connection drops offline through the standard connection sync.
     *
     * @param string $sessionToken Session cookie token to revert to anonymous
     * @throws HilosException On database or runtime failure
     */
    public function deauthenticateSession(string $sessionToken): void
    {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        $userId = $session?->userId;
        if ($session === null || $userId === null) {
            return;
        }

        $session->actions->unbindUser();
        $this->afterDeauthenticate($userId);

        $response = $this->handshakeResponse(null);
        $signalName = $this->handshakeResponseSignalName();
        foreach ($this->sessionConnectionKeys($sessionToken) as $acceptKey) {
            $this->bindConnectionUser($acceptKey, null);
            $this->sendToUser($signalName, $acceptKey, $response->withPendingAck(
                $this->connectionPendingAck($acceptKey),
            ));
        }
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
    public function deauthenticateOtherSessions(int $userId, string $keepSessionToken): void
    {
        foreach (Hilos::$db->sessions->findByUserId($userId) as $session) {
            if ($session->token === $keepSessionToken) {
                continue;
            }

            $this->deauthenticateSession($session->token);
        }
    }

    /**
     * Marks every live socket of a session with the ack its flow just earned (HIL-422).
     *
     * Called by the handler that FINISHES a flow, and before it authenticates the
     * session: the mark has to be on the rows by the time the identity goes out, or the
     * frontend learns it is signed in one frame earlier than it learns there is
     * something to read, and closes the surface in between.
     *
     * Every live socket of the session is marked, not only the one that acted, because the
     * announcement belongs to the session and not to the socket that happened to carry the
     * submit. What that buys is limited, and the limit is worth naming: the login rotation
     * (HIL-582) drops every socket except the initiator's, and they come back on the new
     * token owing nothing — so a second tab reliably keeps the mark only where no rotation
     * follows. The spread still earns its place on the way out, where {@see clearSessionAck()}
     * has to reach every tab the panel is standing in.
     *
     * A session with no live socket marks nothing — the announcement is a thing said to a
     * connection, and there is no one to say it to.
     *
     * @param string $sessionToken Session cookie token whose sockets are marked
     * @param string $ack Ack kind to show (a {@see SessionAck} value)
     * @throws HilosException On database or runtime failure
     */
    public function markSessionAck(string $sessionToken, string $ack): void
    {
        $this->republishSessionAck($sessionToken, $ack);
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
     * @throws HilosException On database or runtime failure
     */
    public function clearSessionAck(string $sessionToken): void
    {
        $this->republishSessionAck($sessionToken, null);
    }

    /**
     * Writes one ack onto every live socket of a session and re-publishes the session scope.
     *
     * The write and the re-publish are one step on purpose: the frontend draws from the
     * projection alone, so a mark nobody published is a mark nobody sees, and the two
     * drifting apart is the only way this mechanism can fail silently.
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
     * @throws HilosException On database or runtime failure
     */
    private function republishSessionAck(string $sessionToken, ?string $ack): void
    {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            return;
        }

        $response = $this->handshakeResponse($session)->withPendingAck($ack);
        $signalName = $this->handshakeResponseSignalName();
        foreach ($this->sessionConnectionKeys($sessionToken) as $acceptKey) {
            $this->markConnectionAck($acceptKey, $ack);
            $this->sendToUser($signalName, $acceptKey, $response);
        }
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
    public function convergeRegistration(
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
                $this->markSessionAck($sessionToken, SessionAck::REGISTERED);
                $this->authenticateSession($sessionToken, $userId, $acceptKey);
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
    public function abandonRegistration(string $sessionToken, string $initiatorAcceptKey): void
    {
        // Collect first: releasing a waiter mutates the collection a foreach would walk.
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
    public function grantRecoveryToSession(
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
    public function convergeRecovery(
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
     * Routes one frame the users library addressed to this holder (HIL-622).
     *
     * Called from the owning agent's `onSignalAgent()`, under the five cases that
     * {@see HilosSessionHostInterface::SESSION_HOST_SIGNALS} names. The switch lives here
     * rather than in the project because what each frame means is the framework's: the
     * library ends a ceremony by saying what happened, and the order the holder then acts
     * in - mark the sockets, raise the session, settle the tabs, answer - is the mechanism
     * itself (HIL-422). A project that re-implemented it could only ever re-implement it
     * differently.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one of the holder's frames
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws HilosException On database, runtime, or session failure
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws InvalidArgumentException When the reply frame cannot be named
     */
    final protected function handleSessionHostFrame(AgentSignalData $data, string $name): void
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

            default:
                throw new AgentUnknownSignalException($name);
        }
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
    final protected function rollBackRegistrationWaiters(string $sessionToken, string $identifier): void
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
    final protected function sweepRegistrationWaiters(): void
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

        // Collect first: releasing a waiter mutates the collection a foreach would walk.
        $parked = [];
        foreach (Hilos::$rt->hilosRegistrationWaiters as $waiter) {
            $parked[] = $waiter->acceptKey;
        }

        foreach ($parked as $acceptKey) {
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
    final protected function sweepRecoveryWaiters(): void
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

        // Collect first: releasing a waiter mutates the collection a foreach would walk.
        $parked = [];
        foreach (Hilos::$rt->hilosRecoveryWaiters as $waiter) {
            $parked[] = $waiter->acceptKey;
        }

        foreach ($parked as $acceptKey) {
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
        if ($frame->ack !== null) {
            $this->markSessionAck($frame->sessionToken, $frame->ack);
        }

        $this->authenticateSession($frame->sessionToken, $frame->userId, $frame->acceptKey);
        $this->answerLibraryAction($frame->acceptKey, $frame->action, $frame->requestId, $frame->outcome);
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
        // Before the sign-in: the surface closes on the session coming up, so the mark has
        // to be on the sockets by the time that frame goes out (HIL-422).
        $this->markSessionAck($frame->winnerSessionToken, SessionAck::REGISTERED);
        $this->authenticateSession($frame->winnerSessionToken, $frame->userId, $frame->initiatorAcceptKey);

        Hilos::$rt->hilosRegistrationWaiters->actions->release($frame->initiatorAcceptKey);
        $this->convergeRegistration(
            $frame->identifier,
            $frame->userId,
            $frame->initiatorAcceptKey,
            $frame->winnerSessionToken,
            $frame->losingSessionTokens,
        );
        $this->answerLibraryAction($frame->initiatorAcceptKey, $frame->action, $frame->requestId, $frame->outcome);
    }

    /**
     * Opens the password step for a browser whose recovery code was just accepted.
     *
     * @param AuthRecoveryGrantedSignalData $frame Address, session, and the answer to give
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When a converge or reply frame cannot be named
     */
    private function openRecoveryPasswordStep(AuthRecoveryGrantedSignalData $frame): void
    {
        $this->grantRecoveryToSession($frame->identifier, $frame->sessionToken, $frame->initiatorAcceptKey);
        $this->answerLibraryAction($frame->initiatorAcceptKey, $frame->action, $frame->requestId, $frame->outcome);
    }

    /**
     * Returns an account to the browser that reset its password, and takes it from everyone else.
     *
     * A reset happens when access has leaked, so it ends with one live session and not with
     * one more: the saving browser is signed in, the surfaces waiting on the address are
     * settled, and every OTHER session of the account is dropped to anonymous. The session
     * that stays is named by the token it holds NOW - the sign-in above rotated it, and the
     * frame carries the one the browser presented before that (HIL-582).
     *
     * @param AuthPasswordChangedSignalData $frame Account, session, address, and the answer to give
     * @throws HilosException On database, runtime, or session failure
     * @throws RandomException When the platform CSPRNG cannot mint a rotated session token
     * @throws InvalidArgumentException When a converge or reply frame cannot be named
     */
    private function settleChangedPassword(AuthPasswordChangedSignalData $frame): void
    {
        // Before the sign-in, not after: the surface closes on the session coming up, so
        // the mark has to be on the sockets by the time that frame goes out (HIL-422).
        $this->markSessionAck($frame->sessionToken, SessionAck::PASSWORD_CHANGED);
        $this->authenticateSession($frame->sessionToken, $frame->userId, $frame->acceptKey);
        $this->convergeRecovery($frame->identifier, $frame->sessionToken, $frame->acceptKey);
        $this->deauthenticateOtherSessions(
            $frame->userId,
            Hilos::$rt?->sessionConnectionsSource()?->get($frame->acceptKey)?->sessionToken ?? $frame->sessionToken,
        );
        $this->answerLibraryAction($frame->acceptKey, $frame->action, $frame->requestId, $frame->outcome);
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
        $this->answerLibraryAction($frame->initiatorAcceptKey, $frame->action, $frame->requestId, $frame->outcome);
    }

    /**
     * Answers the sign-in action a frame finished, when there is a caller waiting on it.
     *
     * The library deferred its own reply so that the answer would leave from HERE, behind
     * the identity it announces (HIL-622). Nothing is sent when the frame carries no
     * request id: the session grant is also the ending of an OAuth login, whose action was
     * acked as "accepted, working on it" the moment the browser was sent to the provider.
     *
     * @param string $acceptKey Accept key of the connection that submitted the action
     * @param ?string $action Action name the frame finished, or null when it finished none
     * @param ?string $requestId Request id of the waiting caller, or null when nobody waits
     * @param ?array<string, mixed> $outcome Where the surface goes next, or null for no domain reply
     * @throws InvalidArgumentException When the reply frame cannot be named
     */
    private function answerLibraryAction(
        string $acceptKey,
        ?string $action,
        ?string $requestId,
        ?array $outcome,
    ): void {
        if ($action === null || $requestId === null) {
            return;
        }

        $this->sendActionSuccess(
            $acceptKey,
            $action,
            $requestId,
            $outcome === null ? null : AuthFlowOutcome::fromArray($outcome),
        );
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
