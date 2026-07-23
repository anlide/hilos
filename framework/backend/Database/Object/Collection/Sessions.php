<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
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
     */
    public function findByToken(string $token): ?ObjectSession
    {
        if ($token === '') {
            return null;
        }

        $entitySession = EntitySession::get([EntitySession::token => $token])->first();

        if ($entitySession === null) {
            return null;
        }

        if (!isset($this->objects[$entitySession->id])) {
            $this->objects[$entitySession->id] = ObjectSession::fromEntity($entitySession);
        }

        return $this->objects[$entitySession->id];
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
     */
    public function findByUserId(int $userId): array
    {
        $entitySessions = EntitySession::get([EntitySession::user_id => $userId]);

        $result = [];
        foreach ($entitySessions as $entitySession) {
            if ($entitySession->id === null) {
                continue;
            }
            if (!isset($this->objects[$entitySession->id])) {
                $this->objects[$entitySession->id] = ObjectSession::fromEntity($entitySession);
            }
            $result[] = $this->objects[$entitySession->id];
        }

        return $result;
    }
}
