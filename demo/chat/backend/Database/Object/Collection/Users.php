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

        $entityUsers = EntityUser::get([EntityUser::session_token => $sessionToken]);
        $entityUser = $entityUsers->first();

        if ($entityUser === null) {
            return null;
        }

        $userId = $entityUser->id;
        if ($userId !== null && isset($this->objects[$userId])) {
            return $this->objects[$userId];
        }

        $objectUser = ObjectUser::fromEntity($entityUser);

        if ($userId !== null) {
            $this->objects[$userId] = $objectUser;
        }

        return $objectUser;
    }
}
