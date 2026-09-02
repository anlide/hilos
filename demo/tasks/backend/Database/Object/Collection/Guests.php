<?php

declare(strict_types=1);

namespace Demo\Tasks\Database\Object\Collection;

use Demo\Tasks\Database\Entity\Collection\Guests as EntityGuests;
use Demo\Tasks\Database\Entity\Item\Guest as EntityGuest;
use Demo\Tasks\Database\Object\Item\Guest as ObjectGuest;
use Demo\Tasks\Database\TasksDbContext;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Objects;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Guests - Object collection for tasks guests.
 *
 * Persistence of the name a visitor without an account is known by (HIL-610),
 * keyed by the session token that earned it. The whole collection is read and
 * written one session at a time: nothing here asks a question about guests as a
 * group, because no surface of this demo shows them as one.
 *
 * @extends Objects<ObjectGuest>
 * @method ObjectGuest|null current()
 * @method ObjectGuest|null first()
 * @method ObjectGuest|null last()
 * @method ObjectGuest|null get(int|string $key)
 * @method ObjectGuest|null offsetGet(mixed $offset)
 */
final class Guests extends Objects
{
    public const string OBJECT_CLASS = ObjectGuest::class;
    public const string ENTITY_COLLECTION_CLASS = EntityGuests::class;
    public const string COLLECTION_KEY = TasksDbContext::guests;

    /**
     * Finds the guest row a session token stands behind.
     *
     * @param string $sessionToken Session cookie token (empty string returns null)
     * @return ?ObjectGuest Guest object, or null when this session has no guest row
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findBySessionToken(string $sessionToken): ?ObjectGuest
    {
        if ($sessionToken === '') {
            return null;
        }

        $entityGuest = EntityGuest::get([EntityGuest::session_token => $sessionToken])->first();

        if ($entityGuest?->id === null) {
            return null;
        }

        return $this->hydrateGuest($entityGuest);
    }

    /**
     * Returns the guest row of a session, creating it on first sight.
     *
     * The name is decided by the caller and only used when the row is actually
     * minted: a visitor keeps the name of their first handshake across reconnects
     * and across tabs, which is the whole point of storing it.
     *
     * Two sockets of one browser can reach this at once - the browser opens a
     * second tab while the first is still shaking hands - and both would then read
     * "no row" and insert. The UNIQUE index on the token is the arbiter: the loser
     * re-reads and returns the row the winner wrote, so both tabs end up showing
     * the same name.
     *
     * @param string $sessionToken Session cookie token of the visiting browser
     * @param string $name Display name to give a guest row minted now
     * @return ObjectGuest Guest object of this session
     * @throws DatabaseException If the lookup or insert query fails, or the insert assigns no id
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function ensureForSession(string $sessionToken, string $name): ObjectGuest
    {
        $guest = $this->findBySessionToken($sessionToken);
        if ($guest !== null) {
            return $guest;
        }

        $guest = ObjectGuest::create();
        $guest->sessionToken = $sessionToken;
        $guest->name = $name;
        $guest->createdAt = TimeHelper::getSqlDateTime();
        try {
            $guest->sync();
        } catch (DuplicateEntryException) {
            $winner = $this->findBySessionToken($sessionToken);
            if ($winner === null) {
                throw new DatabaseException('Guest row vanished between the duplicate and the re-read');
            }

            return $winner;
        }

        $id = $guest->id;
        if ($id === null) {
            throw new DatabaseException('Guest insert did not assign an id');
        }

        $this[$id] = $guest;

        return $guest;
    }

    /**
     * Removes the guest row of a session, if it has one.
     *
     * Silent on a session that carries none: the caller is the handshake of an
     * account, and whether that browser ever visited as a guest is not something
     * it knows or has to find out.
     *
     * @param string $sessionToken Session cookie token whose guest row goes
     * @throws DatabaseException If the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteBySessionToken(string $sessionToken): void
    {
        $guest = $this->findBySessionToken($sessionToken);
        if ($guest === null) {
            return;
        }

        $id = $guest->id;
        $guest->delete();

        if ($id !== null) {
            unset($this[$id]);
        }
    }

    /**
     * Returns the object already standing for a guest row, wrapping it on first sight.
     *
     * @param EntityGuest $entityGuest Row to wrap, whose id is known to be set
     * @return ObjectGuest Guest object for that row
     */
    private function hydrateGuest(EntityGuest $entityGuest): ObjectGuest
    {
        $id = (int)$entityGuest->id;
        if (!isset($this->objects[$id])) {
            $this->hydrate($id, ObjectGuest::fromEntity($entityGuest));
        }

        return $this->objects[$id];
    }
}
