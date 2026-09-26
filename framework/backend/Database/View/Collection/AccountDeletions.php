<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AccountDeletions as ObjectAccountDeletions;
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\Database\View\Item\AccountDeletion;

/**
 * AccountDeletions Db collection - people's own requests to delete their accounts (HIL-302).
 *
 * Read-facing representation of the framework-owned hilos_account_deletion table.
 *
 * @extends DbCollection<AccountDeletion, ObjectAccountDeletions>
 */
final class AccountDeletions extends DbCollection
{
    public const string DB_ITEM_CLASS = AccountDeletion::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountDeletions::class;

    /**
     * The request of a person that still stands, if any.
     *
     * @param int $userId Person
     * @return ?AccountDeletion The standing request, or null when there is none
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function liveOf(int $userId): ?AccountDeletion
    {
        return $this->itemFor($this->objectCollection->liveOf($userId));
    }

    /**
     * The standing requests whose erasure time has come.
     *
     * @param string $now Current moment (SQL datetime)
     * @return list<AccountDeletion> Requests due, oldest first
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function dueBy(string $now): array
    {
        $result = [];
        foreach ($this->objectCollection->dueBy($now) as $object) {
            $item = $this->itemFor($object);
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Wraps one loaded request, if any.
     *
     * @param ?ObjectAccountDeletion $object Loaded request, or null
     * @return ?AccountDeletion Its item, or null
     * @throws InvalidArgumentException When the object type does not match the collection
     * @throws LogicException When collection class constants are not configured
     */
    private function itemFor(?ObjectAccountDeletion $object): ?AccountDeletion
    {
        $id = $object?->id;
        if ($object === null || $id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($id, $object);
    }
}
