<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\Entity\Collection\Sessions as EntitySessions;
use Demo\EcommerceShop\Database\Entity\Item\Session as EntitySession;
use Demo\EcommerceShop\Database\Object\Item\Session as ObjectSession;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Sessions as FrameworkSessions;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\HilosException;

/**
 * Object collection for shop sessions.
 *
 * @method ObjectSession|null current()
 * @method ObjectSession|null first()
 * @method ObjectSession|null last()
 * @method ObjectSession|null get(int|string $key)
 * @method ObjectSession|null offsetGet(mixed $offset)
 */
final class Sessions extends FrameworkSessions
{
    public const string OBJECT_CLASS = ObjectSession::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySessions::class;

    /**
     * Reads the active guest coordinate straight from the stored session, which can be ahead of the Object mirror.
     *
     * @param int $sessionId Session primary key
     * @param ?string $token Token to match, when the caller acts for a connection
     * @return ?int Current guest cart epoch, or null when the session does not qualify
     * @throws DatabaseException When the keyed read fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function activeGuestCartEpoch(int $sessionId, ?string $token = null): ?int
    {
        return self::guestEpoch(EntitySession::getById($sessionId), $token);
    }

    /**
     * Locks the persisted session before any of its guest cart rows or transfer records.
     *
     * @param int $sessionId Session primary key
     * @param bool $waitForLock Wait for a concurrent transaction holding the session lock
     * @return ?EntitySession Fresh row for the duration of the caller's PRIMARY transaction
     * @throws HilosException When there is no PRIMARY transaction or the locking read fails
     */
    public function lockStoredSession(int $sessionId, bool $waitForLock = true): ?EntitySession
    {
        $index = EntitySession::getDatabase()->connectionIndex;
        if (Database::runtimeTransactionLevel($index) === null) {
            throw new DatabaseException('The guest cart session lock requires a PRIMARY transaction.');
        }

        $sql = 'SELECT * FROM ' . EntitySession::getSqlTableName() . ' WHERE `id` = ? FOR UPDATE';
        if (!$waitForLock) {
            $sql .= ' NOWAIT';
        }
        $row = Database::sql($sql, [$sessionId], index: $index)->firstRow();

        return $row === null ? null : EntitySession::fromRow($row);
    }

    /**
     * Checks a mint against current storage even when the caller opened an earlier read snapshot.
     *
     * @param string $token Mint candidate
     * @return bool Whether a saved session already holds this token
     * @throws HilosException When the locking read fails
     */
    public function hasLockedToken(string $token): bool
    {
        return Database::sql(
            'SELECT `id` FROM ' . EntitySession::getSqlTableName() . ' WHERE `token` = ? FOR UPDATE',
            [$token],
            index: EntitySession::getDatabase()->connectionIndex,
        )->firstRow() !== null;
    }

    /**
     * @param int $sessionId Session primary key
     * @param ?string $token Token to match, when the caller acts for a connection
     * @param bool $waitForLock Wait for a concurrent transaction holding the session lock
     * @return ?int Active guest cart epoch under the session's write lock
     * @throws HilosException When the locking read fails
     */
    public function lockActiveGuestCartEpoch(int $sessionId, ?string $token = null, bool $waitForLock = true): ?int
    {
        return self::guestEpoch($this->lockStoredSession($sessionId, $waitForLock), $token);
    }

    /**
     * @param ?EntitySession $session Fresh session row
     * @param ?string $token Token to match, when supplied
     * @return ?int Active guest cart epoch if the session qualifies
     */
    private static function guestEpoch(?EntitySession $session, ?string $token): ?int
    {
        if ($session === null || $session->user_id !== null || $session->impersonator_user_id !== null
            || ($token !== null && $session->token !== $token)
            || ($session->expires_at !== null && $session->expires_at <= TimeHelper::nowMs())
        ) {
            return null;
        }

        return $session->cart_epoch;
    }
}
