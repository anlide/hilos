<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Actions\Item\SessionActions;
use Hilos\Database\Object\Collection\Sessions as ObjectSessions;
use Hilos\Database\Object\Item\Session as ObjectSession;
use Hilos\Database\View\Collection\Sessions as DbCollectionSessions;
use Hilos\Database\View\Item\Session;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SessionsActions - write operations for the Sessions collection.
 *
 * @extends DbActions<Session, ObjectSessions>
 * @property-read DbCollectionSessions $collection
 * @property-read ObjectSessions $objectCollection
 */
final class SessionsActions extends DbActions
{
    /**
     * Creates an anonymous session (no bound user) for a fresh cookie token.
     *
     * @param string $token Session cookie token (32 lowercase hex characters)
     * @return Session Created anonymous session
     * @throws InvalidFormatException When the token is not a 32-character lowercase hex string
     * @throws DuplicateValueException When a session with this token already exists
     * @throws HilosException On database error
     */
    public function createAnonymous(string $token): Session
    {
        $this->ensureCanWrite();
        SessionToken::ensureValid($token);

        if ($this->objectCollection->findByToken($token) !== null) {
            throw new DuplicateValueException('Session with this token already exists');
        }

        $now = TimeHelper::getSqlDateTime();
        $session = ObjectSession::create();
        $session->token = $token;
        $session->userId = null;
        $session->createdAt = $now;
        $session->lastSeenAt = $now;
        $session->expiresAt = self::expiryFromNow();
        $session->sync();

        $this->addObjectToCollection($session);

        return $this->createDbItemFromObject($session);
    }

    /**
     * Re-inserts a session captured before the database was replaced (HIL-479).
     *
     * The write half of the session carry-over: unlike {@see createAnonymous()}, which stamps
     * the row with now and a fresh expiry, this one restores the captured lifetime verbatim so
     * a carried session neither outlives nor undercuts the one the person already had. Only
     * `last_seen_at` is fresh. Impersonation is deliberately not carried - the row is written
     * for the real person behind the takeover and its impersonator marker stays empty, because
     * the right to view another account was granted in a database that no longer exists.
     *
     * A token that already has a row came back with the archive and is consistent with the
     * restored database; the null return says "nothing to do", not "failed".
     *
     * @param string $token Session cookie token (32 lowercase hex characters)
     * @param int $userId User id the token resolved to in the restored database
     * @param string $createdAt Captured creation time as an SQL datetime
     * @param ?string $expiresAt Captured expiry as an SQL datetime, or null for an open-ended session
     * @return ?Session Created session, or null when the token already holds a row
     * @throws InvalidFormatException When the token is not a 32-character lowercase hex string
     * @throws HilosException On database error
     */
    public function carryOver(string $token, int $userId, string $createdAt, ?string $expiresAt): ?Session
    {
        $this->ensureCanWrite();
        SessionToken::ensureValid($token);

        if ($this->objectCollection->findByToken($token) !== null) {
            return null;
        }

        $session = ObjectSession::create();
        $session->token = $token;
        $session->userId = $userId;
        $session->createdAt = $createdAt;
        $session->lastSeenAt = TimeHelper::getSqlDateTime();
        $session->expiresAt = $expiresAt;
        $session->sync();

        $this->addObjectToCollection($session);

        return $this->createDbItemFromObject($session);
    }

    /**
     * Ages the session holding the given cookie token into the past (test-only).
     *
     * Backs the `test:session:expire` CLI (HIL-397): resolves the session by its
     * cookie token and expires it via {@see SessionActions::expire()}, then returns
     * the expired session object (or null when no session holds the token) so the
     * caller can report it. Mirrors the verification layer's expireActive.
     *
     * @param string $token Session cookie token
     * @return ?ObjectSession The expired session, or null when the token is unknown
     * @throws HilosException On database or write error
     */
    public function expireByToken(string $token): ?ObjectSession
    {
        $session = $this->objectCollection->findByToken($token);
        if ($session === null) {
            return null;
        }

        $this->createDbItemFromObject($session)->actions->expire();

        return $session;
    }

    /**
     * Computes a session expiry timestamp from the configured session lifetime.
     *
     * @return string Expiry as an SQL datetime (now + HILOS_SESSION_COOKIE_MAX_AGE)
     */
    public static function expiryFromNow(): string
    {
        return date('Y-m-d H:i:s', time() + Hilos::$env->int(EnvConstants::HILOS_SESSION_COOKIE_MAX_AGE));
    }
}
