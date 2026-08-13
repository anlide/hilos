<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\SessionsActions;
use Hilos\Database\Actions\Item\SessionActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Sessions as ObjectSessions;
use Hilos\Database\View\Item\Session;

/**
 * Sessions - Db collection of Session items.
 *
 * Read-facing API for the framework-owned hilos_session table. Write actions
 * (bind / unbind / touch / impersonator / create) live on {@see SessionsActions}
 * and {@see SessionActions}.
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

    /**
     * Lists every session bound to a user (HIL-378).
     *
     * The read half of account-merge force-logout: the merge orchestrator resolves
     * a loser's live sessions to revert each to anonymous. Delegates to the object
     * collection and wraps each row as a Db item; an anonymous or unused account
     * yields an empty list.
     *
     * @param int $userId Owning user id
     * @return list<Session> Session Db items bound to the user (empty when none)
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the user lookup or lazy session load fails
     */
    public function findByUserId(int $userId): array
    {
        $result = [];
        foreach ($this->objectCollection->findByUserId($userId) as $objectSession) {
            $item = $objectSession->id !== null ? $this->getItemForKey($objectSession->id) : null;
            if ($item === null) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }
}
