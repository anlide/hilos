<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\UserMergesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Collection\UserMerges as ObjectUserMerges;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Database\View\Item\UserMerge;

/**
 * The framework merge table, keyed by the folded account.
 *
 * `Hilos::$db->userMerges[$userId]` is that account's merge row, or null when it was never
 * folded into another one - which is how "is this account merged" is asked. The accounts
 * folded into one person are read by foldedInto(); the live end of the chain a folded
 * account leads to, by liveSurvivorOf().
 *
 * @extends DbCollection<UserMerge, ObjectUserMerges>
 * @method ObjectUserMerges|null getObjectCollection()
 * @method UserMerge|null current()
 * @method UserMerge|null first()
 * @method UserMerge|null last()
 * @method UserMerge|null offsetGet(mixed $offset)
 * @property-read UserMergesActions $actions Actions for write operations
 */
class UserMerges extends DbCollection
{
    public const string DB_ITEM_CLASS = UserMerge::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserMerges::class;

    /**
     * @param int $survivorUserId Person the requested accounts were folded into
     * @return static Complete matching set, including rows not cached in this worker
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When the column names no field of this entity, or an object type does not match
     * @throws DatabaseException When the lookup query fails
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    public function foldedInto(int $survivorUserId): static
    {
        return $this->whereColumnIs(ObjectUserMerge::survivorUserId, $survivorUserId);
    }

    /**
     * The live end of the chain a folded account leads to: the first account up the chain that has
     * no merge row of its own (HIL-1292).
     *
     * Each step reads one row by key, the way "is this account merged" is asked, and runs no query:
     * the table is read process-wide. Null when the account was never folded, when the chain stops
     * at a row whose survivor was erased before HIL-1200 began taking folded accounts along, and
     * when the rows close a loop - a chain that names no live account has no end to point at.
     *
     * @param int $userId Folded account asked about
     * @return ?int Live account the chain ends at, or null when there is none
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match the collection
     * @throws DatabaseException When lazy-loading a merge row from the database fails
     */
    public function liveSurvivorOf(int $userId): ?int
    {
        $visited = [];
        $current = $userId;
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $merge = $this[$current];
            if ($merge === null) {
                return $current === $userId ? null : $current;
            }
            $current = $merge->survivorUserId;
            if ($current === null) {
                return null;
            }
        }

        return null;
    }
}
