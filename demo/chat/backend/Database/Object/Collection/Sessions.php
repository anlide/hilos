<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Collection\Sessions as EntitySessions;
use Demo\Chat\Database\Entity\Item\Session as EntitySession;
use Demo\Chat\Database\Object\Item\Session as ObjectSession;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * Sessions - Object collection for sessions.
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
    public const string COLLECTION_KEY = ChatDbContext::sessions;

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
}
