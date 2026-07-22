<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\View\Item\Session;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Session-host seam graduated from the chat reference (HIL-361).
 *
 * Mixed into a project's monopolistic agent (an {@see \Hilos\Core\Agent\AbstractAgent}
 * subclass, whose `sendToUser()`/`logAgentInfo()` this trait calls) to own the
 * three session-lifecycle cores that used to live inline in the chat agent:
 * resolving a handshake token to a session, upgrading a live session to a user
 * (login/register), and reverting it to anonymous (logout). Each core drives the
 * framework-owned session ORM and re-points the session's live connections, then
 * delegates the project-specific parts — building the identity handshake payload,
 * reaching the project's runtime connection registry, and the emitted signal
 * name — through the abstract hooks below.
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
     * Returns the live connections belonging to a session token.
     *
     * Project-owned because the runtime connection registry is a project runtime
     * collection; the project resolves it (e.g. through
     * {@see \Hilos\Runtime\State\Collection\HilosConnections::findAllBySessionToken()}).
     *
     * @param string $sessionToken Session cookie token
     * @return array<string, HilosConnection> Accept key => connection map (empty for an unknown token)
     */
    abstract protected function sessionConnections(string $sessionToken): array;

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
     * Authenticates a live session: binds it to a user, re-points the session's
     * active connections to that user, and re-emits the handshake response so their
     * frontends populate the current user.
     *
     * The upgrade seam login and register call to promote a session; the symmetric
     * downgrade is {@see deauthenticateSession()}. A no-op when the token has no
     * session row.
     *
     * @param string $sessionToken Session cookie token to authenticate
     * @param int $userId Durable user id to bind the session to
     * @throws HilosException On database or runtime failure
     */
    public function authenticateSession(string $sessionToken, int $userId): void
    {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            return;
        }

        $session->actions->bindUser($userId);
        $this->afterAuthenticate($userId);
        Hilos::$ac?->identifyBrowserSessionUser($sessionToken, $userId);

        $response = $this->handshakeResponseFor($session);
        $signalName = $this->handshakeResponseSignalName();
        foreach ($this->sessionConnections($sessionToken) as $connection) {
            $this->bindConnectionUser($connection->acceptKey, $userId);
            $this->sendToUser($signalName, $connection->acceptKey, $response);
        }
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
        foreach ($this->sessionConnections($sessionToken) as $connection) {
            $this->bindConnectionUser($connection->acceptKey, null);
            $this->sendToUser($signalName, $connection->acceptKey, $response);
        }
    }
}
