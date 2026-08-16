<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\RegistrationWaits as EntityRegistrationWaits;
use Hilos\Database\Entity\Item\RegistrationWait as EntityRegistrationWait;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Item\RegistrationWait as ObjectRegistrationWait;
use Hilos\Database\Object\Objects;

/**
 * RegistrationWaits object collection.
 *
 * Persistence primitives of the unfinished-registration memory (HIL-486): write
 * which identifier a session is waiting on, read it back, list the sessions
 * waiting on an address, and drop a wait from either end. Who calls them and when
 * is the flow's business - a code that really went out writes a wait, a
 * confirmation, an expiry sweep or a "not that address?" removes one.
 *
 * @extends Objects<ObjectRegistrationWait>
 * @method ObjectRegistrationWait|null current()
 * @method ObjectRegistrationWait|null first()
 * @method ObjectRegistrationWait|null last()
 * @method ObjectRegistrationWait|null get(int|string $key)
 * @method ObjectRegistrationWait|null offsetGet(mixed $offset)
 */
final class RegistrationWaits extends Objects
{
    public const string OBJECT_CLASS = ObjectRegistrationWait::class;
    public const string ENTITY_COLLECTION_CLASS = EntityRegistrationWaits::class;
    public const string COLLECTION_KEY = HilosDbContext::registrationWaits;

    /**
     * Remembers that a session is waiting on an identifier's code.
     *
     * One session holds one unfinished registration, so a second call re-points the
     * session's existing row instead of adding another: a person runs one flow at a
     * time, and two rows would leave the handshake choosing which step to serve. The
     * UNIQUE index says the same thing to a racing insert, which is caught and
     * finished as the re-point it meant to be.
     *
     * @param string $sessionToken Session cookie token that is waiting
     * @param string $identifier Normalized identifier the session is waiting on
     * @throws EmptyValueException When the session token or the identifier is empty
     * @throws DatabaseException If the lookup or write query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function hold(string $sessionToken, string $identifier): void
    {
        if ($sessionToken === '' || $identifier === '') {
            throw new EmptyValueException('Registration wait needs both a session token and an identifier');
        }

        $wait = $this->findBySession($sessionToken);
        if ($wait !== null) {
            $wait->identifier = $identifier;
            $wait->sync();

            return;
        }

        $wait = ObjectRegistrationWait::create();
        $wait->sessionToken = $sessionToken;
        $wait->identifier = $identifier;
        try {
            $wait->sync();
        } catch (DuplicateEntryException) {
            // Another socket of the same session wrote between the read and the
            // insert. Its row is the session's one row, so it is re-pointed here -
            // the loser of this race still meant "wait on this identifier".
            $raced = $this->findBySession($sessionToken);
            if ($raced !== null) {
                $raced->identifier = $identifier;
                $raced->sync();
            }

            return;
        }

        $id = $wait->id;
        if ($id === null) {
            throw new DatabaseException('Registration wait insert did not assign an id');
        }

        $this->objects[$id] = $wait;
    }

    /**
     * Reads the registration a session left unfinished.
     *
     * What the handshake serves the step from: the session names itself with its
     * cookie, and this answers the identifier it was waiting on, or null when the
     * session has nothing in flight.
     *
     * @param string $sessionToken Session cookie token
     * @return ?ObjectRegistrationWait The session's wait, or null when it has none
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findBySession(string $sessionToken): ?ObjectRegistrationWait
    {
        if ($sessionToken === '') {
            return null;
        }

        return $this->hydrateFirst([EntityRegistrationWait::session_token => $sessionToken]);
    }

    /**
     * Lists the sessions waiting on one identifier.
     *
     * Several is the normal answer, and the reason this is a table rather than a
     * column on the hold: a desktop and a phone can both sit on the code screen of
     * one registration, and both are owed the news when it resolves.
     *
     * @param string $identifier Normalized identifier
     * @return list<string> Session tokens waiting on it (empty when nobody is)
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function sessionTokensFor(string $identifier): array
    {
        if ($identifier === '') {
            return [];
        }

        $tokens = [];
        foreach ($this->hydrateBy([EntityRegistrationWait::identifier => $identifier]) as $wait) {
            $tokens[] = $wait->sessionToken;
        }

        return $tokens;
    }

    /**
     * Forgets what one session was waiting on.
     *
     * The end of a flow as that session experienced it - its code came back, or the
     * person said it was the wrong address. It says nothing about the address
     * itself: another session may still be waiting on the same one, and the hold on
     * it is released elsewhere or not at all.
     *
     * @param string $sessionToken Session cookie token
     * @throws DatabaseException If the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function releaseBySession(string $sessionToken): void
    {
        $wait = $this->findBySession($sessionToken);
        if ($wait !== null) {
            $this->release($wait);
        }
    }

    /**
     * Forgets every session that was waiting on one identifier.
     *
     * The end of a flow as the ADDRESS experienced it - the registration completed,
     * or its hold ran out - so nobody is left waiting on a code that can no longer
     * arrive.
     *
     * @param string $identifier Normalized identifier
     * @throws DatabaseException If the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function releaseByIdentifier(string $identifier): void
    {
        if ($identifier === '') {
            return;
        }

        foreach ($this->hydrateBy([EntityRegistrationWait::identifier => $identifier]) as $wait) {
            $this->release($wait);
        }
    }

    /**
     * Loads and caches the first wait matching a filter.
     *
     * @param array<string, string> $filters Entity filters
     * @return ?ObjectRegistrationWait Wait object, or null when nothing matches
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    private function hydrateFirst(array $filters): ?ObjectRegistrationWait
    {
        foreach ($this->hydrateBy($filters) as $wait) {
            return $wait;
        }

        return null;
    }

    /**
     * Loads and caches every wait matching a filter.
     *
     * @param array<string, string> $filters Entity filters
     * @return list<ObjectRegistrationWait> Wait objects (empty when nothing matches)
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    private function hydrateBy(array $filters): array
    {
        $result = [];
        foreach (EntityRegistrationWait::get($filters) as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->objects[$entity->id] = ObjectRegistrationWait::fromEntity($entity);
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }

    /**
     * Deletes one wait row and drops it from the in-memory index.
     *
     * @param ObjectRegistrationWait $wait Wait to delete
     * @throws DatabaseException If the delete query fails
     */
    private function release(ObjectRegistrationWait $wait): void
    {
        $id = $wait->id;
        $wait->delete();

        if ($id !== null) {
            unset($this->objects[$id]);
        }
    }
}
