<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\Exception\SessionTokenExhaustedException;
use Hilos\Auth\Throttle\ThrottleGate;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\NotImplementedException;
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\View\Item\Session;
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
 * subclass, whose `sendToUser()`/`logAgentInfo()` this trait calls) to own the
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
     * their handshake and get the same answer. A wait whose hold is gone answers null
     * - the row is stale memory of a registration that completed or ran out, and the
     * person belongs on the identifier field, not on a code screen for a code that
     * can no longer be confirmed.
     *
     * The channel is asked for a number only: a code sent to an address goes by mail
     * whatever else is registered, and naming a channel there would be inventing a
     * choice nobody made.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return ?array{identifier: string, kind: string, channel: ?string, expiresAt: int} Step, or null when there is none
     * @throws HilosException When a wait, reservation or verification query fails
     */
    private function pendingRegistrationFor(?Session $session): ?array
    {
        if ($session === null) {
            return null;
        }

        $identifier = Hilos::$db?->registrationWaits->findBySession($session->token)?->identifier;
        if ($identifier === null) {
            return null;
        }

        $reservation = new RegistrationReservationService()->findActive($identifier);
        if ($reservation === null) {
            return null;
        }

        try {
            $kind = IdentifierDetector::kindOf($identifier);
        } catch (InvalidFormatException $e) {
            // A wait is only ever written for an identifier this same classifier
            // accepted, so a row that no longer classifies is corrupt rather than
            // unusual. The session is told it has no step - which is the truthful
            // answer about a registration nobody can finish - and the row is named in
            // the log rather than taken out on the handshake, which every socket of
            // every session depends on.
            $this->logAgentWarning('Registration wait carries an unusable identifier: ' . $e->getMessage());

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
     * @throws HilosException When the wait lookup or the runtime write fails
     */
    final protected function parkRegistrationWait(string $acceptKey, ?Session $session): void
    {
        if ($session === null) {
            return;
        }

        $identifier = Hilos::$db?->registrationWaits->findBySession($session->token)?->identifier;
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
}
