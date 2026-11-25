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
 * @method Session offsetGet(mixed $offset)
 * @property-read SessionsActions $actions Actions for write operations
 */
class Sessions extends DbCollection
{
    public const string DB_ITEM_CLASS = Session::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessions::class;

    /**
     * Reads persisted guest status independently of the worker's session mirror.
     *
     * @param int $sessionId Session primary key
     * @return bool True when the stored session exists and has no bound user
     * @throws DatabaseException When the primary-key read fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function isStoredGuest(int $sessionId): bool
    {
        return $this->objectCollection->isStoredGuest($sessionId);
    }

    /**
     * Reads the binding from storage and holds a shared row lock until the caller's transaction ends.
     *
     * @param int $sessionId Session primary key
     * @param int $userId Expected bound user
     * @return bool Whether the stored session is bound to this user
     * @throws DatabaseException When storage or its database binding refuses the read
     */
    public function isStoredBoundToUser(int $sessionId, int $userId): bool
    {
        return $this->objectCollection->isStoredBoundToUser($sessionId, $userId);
    }

    /**
     * @param int $sessionId Session primary key
     * @param ?string $token Expected cookie token, or null when rotation is immaterial
     * @return bool Whether the persisted session is active and matches the guest binding
     * @throws DatabaseException When the primary-key read fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function isStoredActiveGuest(int $sessionId, ?string $token = null): bool
    {
        return $this->objectCollection->isStoredActiveGuest($sessionId, $token);
    }

    /**
     * Reads the current account binding independently of the worker's session mirror.
     *
     * @param int $sessionId Session primary key
     * @param int $userId Expected bound user
     * @return bool Whether the persisted session is active and bound to that user
     * @throws DatabaseException When the primary-key read fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function isStoredActiveForUser(int $sessionId, int $userId): bool
    {
        return $this->objectCollection->isStoredActiveForUser($sessionId, $userId);
    }

    /**
     * @param int $sessionId Session primary key
     * @param ?int $userId Person the session must act as, or null for a guest
     * @param ?string $token Required for a state frame; null when a token rotation is immaterial
     * @return bool Whether the persisted session matches, independently of the worker's mirror
     * @throws DatabaseException When the primary-key read fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function matchesStoredIdentity(int $sessionId, ?int $userId, ?string $token = null): bool
    {
        return $this->objectCollection->matchesStoredIdentity($sessionId, $userId, $token);
    }

    /**
     * Locks the stored author for the caller's open write transaction.
     *
     * @param int $sessionId Session primary key
     * @param int $userId Author whose write is being admitted
     * @return bool Whether the stored session exists and belongs to the author
     * @throws DatabaseException When there is no row-connection transaction, the lock is busy, or the read fails
     */
    public function lockStoredAuthor(int $sessionId, int $userId): bool
    {
        return $this->objectCollection->lockStoredAuthor($sessionId, $userId);
    }

    /**
     * Checks the active stored binding and holds a shared row lock until the caller's transaction ends.
     *
     * @param int $sessionId Session primary key
     * @param ?int $userId Expected bound user, or null for a guest
     * @return bool Whether the row exists, matches the expected binding and has not expired
     * @throws DatabaseException When the caller has no row-connection transaction, the lock is busy, or the read fails
     */
    public function lockStoredActiveSession(int $sessionId, ?int $userId): bool
    {
        return $this->objectCollection->lockStoredActiveSession($sessionId, $userId);
    }

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
        return $this->sessionsForKeys($this->objectCollection->findExpired(TimeHelper::nowMs(), $limit));
    }

    /**
     * Lists old anonymous sessions whose browser never returned, oldest row first.
     *
     * @param int $cutoffMs Latest first-handshake moment eligible for removal, in epoch milliseconds
     * @param int $limit Maximum rows to load
     * @return static Never-returned session Db items, empty when none
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object or order direction is invalid
     * @throws DatabaseException When the lookup or lazy session load fails
     * @throws ObjectGetIdStringNotImplementedException If a session's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects an item on a non-manual collection
     */
    public function findNeverReturned(int $cutoffMs, int $limit): static
    {
        return $this->sessionsForKeys($this->objectCollection->findNeverReturned($cutoffMs, $limit));
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
