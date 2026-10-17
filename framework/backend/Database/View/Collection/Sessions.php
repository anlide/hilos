<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\SessionsActions;
use Hilos\Database\Actions\Item\SessionActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Collection\Sessions as ObjectSessions;
use Hilos\Database\View\Item\Session;
use Hilos\Utils\Helpers\TimeHelper;

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
class Sessions extends DbCollection
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
     * collection and gathers the Db items into a collection of this class; an
     * anonymous or unused account yields an empty collection.
     *
     * @param int $userId Owning user id
     * @return static Session Db items bound to the user (empty when none)
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the user lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findByUserId(int $userId): static
    {
        return $this->sessionsForKeys($this->objectCollection->findByUserId($userId));
    }

    /**
     * Lists every session holding the "Access closed" card for a blocked account (HIL-289).
     *
     * The read half of an unblock: see the object collection. Delegates to it and gathers the
     * Db items into a collection of this class; an account nobody saw blocked yields an empty
     * collection.
     *
     * @param int $userId Blocked account the mark names
     * @return static Session Db items marked with the account (empty when none)
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findByBlockedUserId(int $userId): static
    {
        return $this->sessionsForKeys($this->objectCollection->findByBlockedUserId($userId));
    }

    /**
     * Returns every session whose proven sign-in waits on a second factor, oldest row first (HIL-1303).
     *
     * The read half of turning the second factor off for the installation: see the object
     * collection. Delegates to it and gathers the Db items into a collection of this class, in
     * the order the rows came; nobody waiting yields an empty collection.
     *
     * @return static Session Db items with a second-factor wait (empty when none)
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findAwaitingSecondFactor(): static
    {
        return $this->sessionsForKeys($this->objectCollection->findAwaitingSecondFactor());
    }

    /**
     * Lists every session in which an administrator is acting as somebody else (HIL-289).
     *
     * The read half of the takeover a blocked administrator loses: see the object collection.
     * Delegates to it and gathers the Db items into a collection of this class; an
     * administrator who impersonates nobody yields an empty collection.
     *
     * @param int $userId Administrator behind the impersonation
     * @return static Session Db items the administrator is impersonating in (empty when none)
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findByImpersonator(int $userId): static
    {
        return $this->sessionsForKeys($this->objectCollection->findByImpersonator($userId));
    }

    /**
     * Lists every session in which some administrator is acting as somebody else, oldest row first (HIL-1308).
     *
     * The read half of switching impersonation off for the installation: see the object
     * collection. Delegates to it and gathers the Db items into a collection of this class, in
     * the order the rows came; nobody impersonating yields an empty collection.
     *
     * @return static Impersonating session Db items (empty when none)
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findImpersonating(): static
    {
        return $this->sessionsForKeys($this->objectCollection->findImpersonating());
    }

    /**
     * Lists every session a person stands in - signed in, taking over, or waiting on a second factor (HIL-302).
     *
     * The read half of the erasure's sign-out; see the object collection for the three places.
     *
     * @param int $userId Person
     * @return static Session Db items naming the person, oldest row first (empty when none)
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findTouchingUser(int $userId): static
    {
        return $this->sessionsForKeys($this->objectCollection->findTouchingUser($userId));
    }

    /**
     * Lists the sessions waiting on one address's registration code (HIL-612).
     *
     * The reverse read of the pending-registration memory: who has to be told when an
     * address resolves. Delegates to the object collection and gathers the Db items into
     * a collection of this class; several is the normal answer, and an address nobody is
     * registering yields an empty collection.
     *
     * @param string $identifier Normalized identifier (empty string returns an empty collection)
     * @return static Session Db items waiting on it (empty when nobody is)
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When the identifier lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findAwaitingRegistration(string $identifier): static
    {
        return $this->sessionsForKeys($this->objectCollection->findAwaitingRegistration($identifier));
    }

    /**
     * Lists sessions whose cookie lifetime has ended, oldest row first.
     *
     * @param int $limit Maximum rows to load
     * @return static Expired session Db items, empty when none
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object or order direction is invalid
     * @throws DatabaseException When the lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findExpired(int $limit): static
    {
        return $this->sessionsForKeys($this->objectCollection->findExpired(TimeHelper::getSqlDateTime(), $limit));
    }

    /**
     * Lists old anonymous sessions whose browser never returned, oldest row first.
     *
     * @param string $cutoffSql Latest first-handshake moment eligible for removal
     * @param int $limit Maximum rows to load
     * @return static Never-returned session Db items, empty when none
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object or order direction is invalid
     * @throws DatabaseException When the lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findNeverReturned(string $cutoffSql, int $limit): static
    {
        return $this->sessionsForKeys($this->objectCollection->findNeverReturned($cutoffSql, $limit));
    }

    /**
     * Gathers the Db items of session keys into a collection of this class, in the order of the keys.
     *
     * The keys come from a finder of the object collection, which hydrated each row on the way;
     * a row that is no longer there when its key is read is left out rather than handed on.
     *
     * @param list<int> $keys Session keys, in the order the rows came
     * @return static Session Db items of the keys (empty when none)
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the loaded object type does not match the collection
     * @throws DatabaseException When a lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    private function sessionsForKeys(array $keys): static
    {
        $sessions = static::initEmpty();
        foreach ($keys as $key) {
            $item = $this->getItemForKey($key);
            if ($item !== null) {
                $sessions->add($item);
            }
        }

        return $sessions;
    }
}
