<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\SessionsActions;
use Demo\Chat\Database\Object\Collection\Sessions as ObjectSessions;
use Demo\Chat\Database\View\Item\Session;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Sessions - Db collection of Session items.
 *
 * @extends DbCollection<Session, ObjectSessions>
 * @method ObjectSessions|null getObjectCollection()
 * @method Session|null current()
 * @method Session|null first()
 * @method Session|null last()
 * @method Session|null offsetGet(mixed $offset)
 * @property-read SessionsActions $actions Actions for write operations
 */
final class Sessions extends DbCollection
{
    public const string DB_ITEM_CLASS = Session::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessions::class;

    /**
     * Finds a session by its cookie token.
     *
     * @param string $token Session cookie token (empty string returns null)
     * @return ?Session Session Db item or null if not found
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the token lookup or lazy session load fails
     */
    public function findByToken(string $token): ?Session
    {
        $objectSession = $this->objectCollection->findByToken($token);

        if ($objectSession?->id === null) {
            return null;
        }

        return $this->getItemForKey($objectSession->id);
    }
}
