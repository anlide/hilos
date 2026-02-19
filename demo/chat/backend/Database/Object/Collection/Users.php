<?php

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Collection\Users as EntityUsers;
use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * Chat users object collection.
 *
 * @extends Objects<ObjectUser>
 */
final class Users extends Objects
{
    public const string OBJECT_CLASS = ObjectUser::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUsers::class;
    public const string COLLECTION_KEY = DbChatContext::users;

    /**
     * Finds user by session token.
     *
     * @param string $sessionToken User session token
     * @return ?ObjectUser User object or null if not found
     * @throws DatabaseException
     */
    public function findBySession(string $sessionToken): ?ObjectUser
    {
        if (empty($sessionToken)) {
            return null;
        }

        $entityUser = EntityUser::get([EntityUser::session_token => $sessionToken])->first();

        if ($entityUser === null) {
            return null;
        }

        if (!isset($this->objects[$entityUser->id])) {
            $this->objects[$entityUser->id] = ObjectUser::fromEntity($entityUser);
        }

        return $this->objects[$entityUser->id];
    }
}
