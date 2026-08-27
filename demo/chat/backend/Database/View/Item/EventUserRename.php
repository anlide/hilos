<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\EventUserRename as ObjectEventUserRename;
use Demo\Chat\Hilos;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * EventUserRename - Db item for user rename event details.
 *
 * @extends DbItem<ObjectEventUserRename>
 * @method __construct(ObjectEventUserRename $objectEventUserRename)
 *
 * @property-read int $eventId
 * @property-read int $targetUserId
 * @property-read ?int $actorUserId
 * @property-read string $oldName
 * @property-read string $newName
 * @property-read ?Event $event Parent chat event
 * @property-read ?User $targetUser Renamed user
 * @property-read ?User $actorUser User who initiated the rename, when known
 */
final class EventUserRename extends DbItem
{
    public const string targetUser = 'targetUser';
    public const string actorUser = 'actorUser';

    /**
     * Property getter (read-only access).
     *
     * @param string $name Property or bridge name
     * @return mixed Property value or related item
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectEventUserRename::eventId => $this->_object->eventId,
            ObjectEventUserRename::targetUserId => $this->_object->targetUserId,
            ObjectEventUserRename::actorUserId => $this->_object->actorUserId,
            ObjectEventUserRename::oldName => $this->_object->oldName,
            ObjectEventUserRename::newName => $this->_object->newName,
            ChatDbContext::event => Hilos::$db->events[$this->_object->eventId],
            self::targetUser => Hilos::$db->users[$this->_object->targetUserId],
            self::actorUser => Hilos::$db->users[$this->_object->actorUserId],
            default => parent::__get($name),
        };
    }
}
