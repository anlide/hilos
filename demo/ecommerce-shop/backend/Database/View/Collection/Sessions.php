<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\SessionsActions;
use Demo\EcommerceShop\Database\Object\Collection\Sessions as ObjectSessions;
use Demo\EcommerceShop\Database\View\Item\Session;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\Sessions as FrameworkSessions;
use Hilos\HilosException;

/**
 * View collection for shop sessions.
 *
 * @method ObjectSessions|null getObjectCollection()
 * @method Session|null current()
 * @method Session|null first()
 * @method Session|null last()
 * @method Session offsetGet(mixed $offset)
 * @property-read SessionsActions $actions
 */
final class Sessions extends FrameworkSessions
{
    public const string DB_ITEM_CLASS = Session::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessions::class;

    /**
     * @param int $sessionId Session primary key
     * @param ?string $token Required token for a connection-bound read
     * @return ?int Epoch of the stored unexpired guest session, or null when it does not qualify
     * @throws DatabaseException When the keyed read fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function activeGuestCartEpoch(int $sessionId, ?string $token = null): ?int
    {
        return $this->objectCollection->activeGuestCartEpoch($sessionId, $token);
    }

    /**
     * @param int $sessionId Session primary key
     * @param ?string $token Required token for a connection-bound write
     * @param bool $waitForLock Wait for a concurrent transaction holding the session lock
     * @return ?int Active guest cart epoch under the caller's PRIMARY transaction lock
     * @throws HilosException When the locking read fails
     */
    public function lockActiveGuestCartEpoch(int $sessionId, ?string $token = null, bool $waitForLock = true): ?int
    {
        return $this->objectCollection->lockActiveGuestCartEpoch($sessionId, $token, $waitForLock);
    }

    /**
     * Locks the session before cleanup can look at its lines and transfers, regardless of its identity.
     *
     * @param int $sessionId Session primary key
     * @return ?int Stored cart epoch, null if the session is gone
     * @throws HilosException When the locking read fails
     */
    public function lockCartEpoch(int $sessionId): ?int
    {
        return $this->objectCollection->lockStoredSession($sessionId)?->cart_epoch;
    }
}
