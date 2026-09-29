<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;

/**
 * UserRename - Db item for one row of the framework rename journal.
 *
 * Read-only scalar projection of the durable journal row. A row is never edited, so the
 * framework mounts no item actions over it and there is no runtime overlay.
 *
 * @extends DbItem<ObjectUserRename>
 * @method __construct(ObjectUserRename $objectUserRename)
 *
 * @property-read ?int $id Journal row id (primary key)
 * @property-read int $userId Renamed person
 * @property-read ?int $renamedByUserId Person who did the rename, or null when it was not a person
 * @property-read string $oldName Name before the rename
 * @property-read string $newName Name after the rename
 * @property-read string $renamedAt When the rename was recorded
 */
class UserRename extends DbItem
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
            ObjectUserRename::id => $this->_object->id,
            ObjectUserRename::userId => $this->_object->userId,
            ObjectUserRename::renamedByUserId => $this->_object->renamedByUserId,
            ObjectUserRename::oldName => $this->_object->oldName,
            ObjectUserRename::newName => $this->_object->newName,
            ObjectUserRename::renamedAt => $this->_object->renamedAt,
            default => parent::__get($name),
        };
    }
}
