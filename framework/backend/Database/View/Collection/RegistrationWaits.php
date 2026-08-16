<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\RegistrationWaitsActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\RegistrationWaits as ObjectRegistrationWaits;
use Hilos\Database\View\Item\RegistrationWait;

/**
 * RegistrationWaits Db collection.
 *
 * Read-facing representation of the framework-owned hilos_registration_wait
 * table (HIL-486). The write and read primitives live on the object collection
 * ({@see ObjectRegistrationWaits}), which the session host and the registration
 * flow drive; nothing is published to the frontend - a browser learns about its
 * own unfinished registration through the handshake response, and about nobody
 * else's at all.
 *
 * @extends DbCollection<RegistrationWait, ObjectRegistrationWaits>
 * @method ObjectRegistrationWaits|null getObjectCollection()
 * @property-read RegistrationWaitsActions $actions Actions for write operations
 */
final class RegistrationWaits extends DbCollection
{
    public const string DB_ITEM_CLASS = RegistrationWait::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectRegistrationWaits::class;

    /**
     * Reads the registration one session left unfinished.
     *
     * @param string $sessionToken Session cookie token (empty string returns null)
     * @return ?RegistrationWait The session's wait, or null when it has none
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the wait lookup fails
     */
    public function findBySession(string $sessionToken): ?RegistrationWait
    {
        $wait = $this->objectCollection->findBySession($sessionToken);
        if ($wait?->id === null) {
            return null;
        }

        return $this->getItemForKey($wait->id);
    }

    /**
     * Lists the sessions waiting on one identifier.
     *
     * Session tokens rather than items, because that is what the answer is used for:
     * addressing the sockets of those sessions. Several is the normal case - one
     * registration can be watched from a desktop and a phone at once.
     *
     * @param string $identifier Normalized identifier
     * @return list<string> Session tokens waiting on it (empty when nobody is)
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws DatabaseException When the wait lookup fails
     */
    public function sessionTokensFor(string $identifier): array
    {
        return $this->objectCollection->sessionTokensFor($identifier);
    }
}
