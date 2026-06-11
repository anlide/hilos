<?php

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\UsersActions;
use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Database\View\Item\User;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Users - Db collection of User items with additional filtering methods.
 *
 * @extends DbCollection<User, ObjectUsers>
 * @method ObjectUsers|null getObjectCollection()
 * @method User|null current()
 * @method User|null first()
 * @method User|null last()
 * @method User|null offsetGet(mixed $offset)
 * @property-read UsersActions $actions Actions for write operations
 */
final class Users extends DbCollection
{
    public const string DB_ITEM_CLASS = User::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUsers::class;

    /**
     * Returns every user as the curated `{id, name}` snapshot projection.
     *
     * The users collection is key-lazy, so the read preloads the full set
     * first; plain iteration would only walk already loaded rows.
     *
     * @return list<array{id: int, name: string}> User rows in collection order
     * @throws DatabaseException When loading the full user set fails
     */
    public function snapshotRows(): array
    {
        $this->objectCollection->preloadAll();
        $userRows = [];
        foreach ($this as $dbUser) {
            $userRows[] = [
                EntityUser::id => (int)$dbUser->id,
                EntityUser::name => $dbUser->name,
            ];
        }

        return $userRows;
    }

    /**
     * Finds user by session token.
     *
     * @param string $sessionToken User session token (empty string returns null)
     * @return ?User User Db item or null if not found
     * @throws DatabaseException On database error
     */
    public function findBySession(string $sessionToken): ?User
    {
        $objectUser = $this->objectCollection->findBySession($sessionToken);

        if ($objectUser?->id === null) {
            return null;
        }

        return $this->getItemForKey($objectUser->id);
    }
}
