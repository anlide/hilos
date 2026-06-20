<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Database\View\Item;

use Demo\SimpleTodo\Database\Object\Item\EventUserRename as ObjectEventUserRename;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * EventUserRename - Db item for one user-rename audit row.
 *
 * Read-only scalar projection of the durable audit row. Append-only: no item
 * actions, no runtime overlay.
 *
 * @extends DbItem<ObjectEventUserRename>
 * @method __construct(ObjectEventUserRename &$objectEventUserRename)
 *
 * @property-read ?int $id Audit row id (primary key)
 * @property-read int $targetUserId Renamed user id
 * @property-read string $oldName Previous display name
 * @property-read string $newName New display name
 * @property-read string $timestamp When the rename was recorded
 */
final class EventUserRename extends DbItem
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
            ObjectEventUserRename::id => $this->_object->id,
            ObjectEventUserRename::targetUserId => $this->_object->targetUserId,
            ObjectEventUserRename::oldName => $this->_object->oldName,
            ObjectEventUserRename::newName => $this->_object->newName,
            ObjectEventUserRename::timestamp => $this->_object->timestamp,
            default => parent::__get($name),
        };
    }
}
