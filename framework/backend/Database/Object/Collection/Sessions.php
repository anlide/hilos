<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Collection\Sessions as EntitySessions;
use Hilos\Database\Entity\Item\Session as EntitySession;
use Hilos\Database\Object\Item\Session as ObjectSession;
use Hilos\Database\Object\Objects;

/**
 * Sessions object collection.
 *
 * @extends Objects<ObjectSession>
 * @method ObjectSession|null current()
 * @method ObjectSession|null first()
 * @method ObjectSession|null last()
 * @method ObjectSession|null get(int|string $key)
 * @method ObjectSession|null offsetGet(mixed $offset)
 */
final class Sessions extends Objects
{
    public const string OBJECT_CLASS = ObjectSession::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySessions::class;
    public const string COLLECTION_KEY = HilosDbContext::sessions;

    /**
     * Finds a session by its cookie token.
     *
     * @param string $token Session cookie token (empty string returns null)
     * @return ?ObjectSession Session object or null if not found
     * @throws DatabaseException If database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findByToken(string $token): ?ObjectSession
    {
        if ($token === '') {
            return null;
        }

        $entitySession = EntitySession::get([EntitySession::token => $token])->first();

        if ($entitySession?->id === null) {
            return null;
        }

        return $this->hydrateSession($entitySession);
    }

    /**
     * Lists every session bound to a user (HIL-378).
     *
     * The account-merge force-logout read path: the merge orchestrator resolves
     * a loser's live sessions to revert each to anonymous. A user may hold several
     * sessions (distinct devices/tabs), so this returns all of them; an anonymous
     * or unused account yields an empty list.
     *
     * @param int $userId Owning user id
     * @return list<ObjectSession> Session objects bound to the user (empty when none)
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findByUserId(int $userId): array
    {
        return $this->hydrateAll(EntitySession::get([EntitySession::user_id => $userId]));
    }

    /**
     * Lists the sessions waiting on one address's registration code (HIL-612).
     *
     * The reverse read of the pending-registration memory, and the reason the column
     * carries an index of its own: several is the normal answer - a desktop and a phone
     * can both sit on the code screen of one address, and both are owed the news when
     * it resolves.
     *
     * @param string $identifier Normalized identifier (empty string returns an empty list)
     * @return list<ObjectSession> Session objects waiting on it (empty when nobody is)
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findAwaitingRegistration(string $identifier): array
    {
        if ($identifier === '') {
            return [];
        }

        return $this->hydrateAll(
            EntitySession::get([EntitySession::pending_registration_identifier => $identifier]),
        );
    }

    /**
     * Clears every pending registration not rewritten since a moment (HIL-612).
     *
     * The sweep primitive behind the cron rule: an abandoned registration has to stop
     * being served on the handshake, or a person coming back days later lands on a code
     * screen for a code that expired long ago. Age of the WRITE is the whole criterion -
     * the reservation table is deliberately not consulted, because a project that never
     * registers anybody does not have one and must still be swept.
     *
     * The count is returned rather than the rows: nobody is told about this sweep, and
     * the number is what the log line is built from. The sessions themselves survive
     * untouched - only their memory of an unfinished registration goes.
     *
     * @param string $cutoffSql Oldest write moment a wait may keep, as an SQL datetime
     * @return int Number of sessions whose wait this call cleared
     * @throws DatabaseException If the lookup or write query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function releaseStalePendingRegistrations(string $cutoffSql): int
    {
        $stale = $this->hydrateAll(EntitySession::get(
            '`' . EntitySession::pending_registration_since . '` <= ?',
            [$cutoffSql],
        ));

        foreach ($stale as $session) {
            $session->pendingRegistrationIdentifier = null;
            $session->pendingRegistrationSince = null;
            $session->sync();
        }

        return count($stale);
    }

    /**
     * Loads and caches every session of an entity query result.
     *
     * Typed on the base collection rather than on {@see EntitySessions}: the string-filter
     * form of `Entity::get()` hands back a plain {@see EntityCollection}, and the narrower
     * hint would refuse the very query the sweep is built on.
     *
     * @param EntityCollection<EntitySession> $entitySessions Entity query result to wrap
     * @return list<ObjectSession> Session objects (empty when nothing matched)
     */
    private function hydrateAll(EntityCollection $entitySessions): array
    {
        $result = [];
        foreach ($entitySessions as $entitySession) {
            if ($entitySession->id === null) {
                continue;
            }
            $result[] = $this->hydrateSession($entitySession);
        }

        return $result;
    }

    /**
     * Returns the object already standing for a session row, wrapping it on first sight.
     *
     * The in-memory object is the one returned whenever it exists, and deliberately so: a
     * truth-source agent may hold unsynced changes on it, and handing back a second wrapper
     * over the same row would let two of them disagree about the same session.
     *
     * @param EntitySession $entitySession Row to wrap, whose id is known to be set
     * @return ObjectSession Session object for that row
     */
    private function hydrateSession(EntitySession $entitySession): ObjectSession
    {
        if (!isset($this->objects[$entitySession->id])) {
            $this->hydrate($entitySession->id, ObjectSession::fromEntity($entitySession));
        }

        return $this->objects[$entitySession->id];
    }
}
