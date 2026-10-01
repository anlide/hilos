<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;

/**
 * UserMerge - Db item for one row of the framework merge table.
 *
 * Read-only scalar projection of the durable merge row. A row is never edited, so the
 * framework mounts no item actions over it and there is no runtime overlay.
 *
 * @extends DbItem<ObjectUserMerge>
 * @method __construct(ObjectUserMerge $objectUserMerge)
 *
 * @property-read int $userId Folded account (primary key)
 * @property-read ?int $survivorUserId Account it was folded into, or null on an older row left when its survivor was erased
 * @property-read ?string $mergedAt When the merge was recorded, or null on a row carried over from a column that never kept it
 */
class UserMerge extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectUserMerge::userId => $this->_object->userId,
            ObjectUserMerge::survivorUserId => $this->_object->survivorUserId,
            ObjectUserMerge::mergedAt => $this->_object->mergedAt,
            default => parent::__get($name),
        };
    }
}
