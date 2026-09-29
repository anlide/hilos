<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\UserRenamesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\View\Item\UserRename;

/**
 * The framework rename journal, addressed by row id; one person's rows are read by byUser().
 *
 * @extends DbCollection<UserRename, ObjectUserRenames>
 * @method ObjectUserRenames|null getObjectCollection()
 * @method UserRename|null current()
 * @method UserRename|null first()
 * @method UserRename|null last()
 * @method UserRename|null offsetGet(mixed $offset)
 * @property-read UserRenamesActions $actions Actions for write operations
 */
class UserRenames extends DbCollection
{
    public const string DB_ITEM_CLASS = UserRename::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserRenames::class;

    /**
     * @param int $userId Renamed person whose rows are requested
     * @return static Complete matching set, including rows not cached in this worker
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When the column names no field of this entity, or an object type does not match
     * @throws DatabaseException When the lookup query fails
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    public function byUser(int $userId): static
    {
        return $this->whereColumnIs(ObjectUserRename::userId, $userId);
    }
}
