<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Actions\Item\SessionActions;
use Demo\Chat\Database\Object\Collection\Sessions as ObjectSessions;
use Demo\Chat\Database\Object\Item\Session as ObjectSession;
use Demo\Chat\Database\View\Collection\Sessions as DbCollectionSessions;
use Demo\Chat\Database\View\Item\Session;
use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Actions\Collection\DbActions;
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
     * @param string $token Session cookie token (32 hex characters)
     * @return Session Created anonymous session
     * @throws InvalidFormatException When the token is not a 32-character hex string
     * @throws DuplicateValueException When a session with this token already exists
     * @throws HilosException On database error
     */
    public function createAnonymous(string $token): Session
    {
        $this->ensureCanWrite();

        if (strlen($token) !== 32 || !ctype_xdigit($token)) {
            throw new InvalidFormatException('Invalid session token format. Expected 32 hex characters.');
        }

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
