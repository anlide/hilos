<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

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
     * Computes a session expiry timestamp from the configured session lifetime.
     *
     * @return string Expiry as an SQL datetime (now + HILOS_SESSION_COOKIE_MAX_AGE)
     */
    public static function expiryFromNow(): string
    {
        return date('Y-m-d H:i:s', time() + Hilos::$env->int(EnvConstants::HILOS_SESSION_COOKIE_MAX_AGE));
    }
}
