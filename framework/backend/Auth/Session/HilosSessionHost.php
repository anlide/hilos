<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\Exception\SessionTokenExhaustedException;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\View\Item\Session;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use Random\RandomException;

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

        $response = $this->handshakeResponseFor($session);
        $signalName = $this->handshakeResponseSignalName();

        if ($rotated === null) {
            // Nothing was rotated, so the session still answers to the token every one of
            // its connections named, and every one of them still belongs to it. Re-point
            // them all, exactly as this seam always did: the caller with no initiator is
            // acting on somebody else's session (the impersonation CLI), and the tabs of
            // that session have to learn who they are now.
            foreach ($this->sessionConnectionKeys($sessionToken) as $acceptKey) {
                $this->bindConnectionUser($acceptKey, $userId);
                $this->sendToUser($signalName, $acceptKey, $response);
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
        $this->sendToUser($signalName, $initiatorAcceptKey, $response);

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

        $response = new HandshakeResponseSignalData();
        $signalName = $this->handshakeResponseSignalName();
        foreach ($this->sessionConnectionKeys($sessionToken) as $acceptKey) {
            $this->bindConnectionUser($acceptKey, null);
            $this->sendToUser($signalName, $acceptKey, $response);
        }
    }
}
