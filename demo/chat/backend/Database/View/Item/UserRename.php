<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\Actions\Item\UserRenameActions;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\UserRename as ObjectUserRename;
use Demo\Chat\Hilos;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\UserRename as FrameworkUserRename;
use Hilos\HilosException;

/**
 * UserRename - Db item for a row of chat's rename journal: the framework's fields and the event
 * of the room's feed that shows the rename.
 *
 * @method __construct(ObjectUserRename $objectUserRename)
 *
 * @property-read ?int $eventId Feed event that shows the rename, or null when there is none (yet, or any more)
 * @property-read ?Event $event Feed event that shows the rename
 * @property-read UserRenameActions $actions Actions for write operations on this row
 */
final class UserRename extends FrameworkUserRename
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property or bridge name
     * @return mixed Property value, related item, or item actions
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectUserRename::eventId => $this->_object->eventId,
            ChatDbContext::event => Hilos::$db->events[$this->_object->eventId],
            default => parent::__get($name),
        };
    }
}
