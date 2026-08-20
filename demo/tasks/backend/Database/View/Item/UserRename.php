<?php

declare(strict_types=1);

namespace Demo\Tasks\Database\View\Item;

use Demo\Tasks\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * UserRename - Db item for one user-rename audit row.
 *
 * Read-only scalar projection of the durable audit row. Append-only: no item
 * actions, no runtime overlay.
 *
 * @extends DbItem<ObjectUserRename>
 * @method __construct(ObjectUserRename &$objectUserRename)
 *
 * @property-read ?int $id Audit row id (primary key)
 * @property-read int $targetUserId Renamed user id
 * @property-read string $oldName Previous display name
 * @property-read string $newName New display name
 * @property-read string $timestamp When the rename was recorded
 */
final class UserRename extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return int|string|null Property value
     * @throws PropertyNotFoundException If property does not exist
     */
    public function __get(string $name): int|string|null
    {
        return match ($name) {
            ObjectUserRename::id => $this->_object->id,
            ObjectUserRename::targetUserId => $this->_object->targetUserId,
            ObjectUserRename::oldName => $this->_object->oldName,
            ObjectUserRename::newName => $this->_object->newName,
            ObjectUserRename::timestamp => $this->_object->timestamp,
            default => parent::__get($name),
        };
    }
}
