<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\EventUserRename as ObjectEventUserRename;
use Demo\Chat\Hilos;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * EventUserRename - Db item for user rename event details.
 *
 * @extends DbItem<ObjectEventUserRename>
 * @method __construct(ObjectEventUserRename &$objectEventUserRename)
 *
 * @property-read int $eventId
 * @property-read int $targetUserId
 * @property-read ?int $actorUserId
 * @property-read string $oldName
 * @property-read string $newName
 * @property-read ?Event $event Parent chat event
 * @property-read ?User $user Renamed user
 * @property-read ?User $actorUser User who initiated the rename, when known
 */
final class EventUserRename extends DbItem
{
    public const string actorUser = 'actorUser';

    /**
     * Property getter (read-only access).
     *
     * @param string $name Property or bridge name
     * @return mixed Property value or related item
     * @throws PropertyNotFoundException If property does not exist
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectEventUserRename::eventId => $this->_object->eventId,
            ObjectEventUserRename::targetUserId => $this->_object->targetUserId,
            ObjectEventUserRename::actorUserId => $this->_object->actorUserId,
            ObjectEventUserRename::oldName => $this->_object->oldName,
            ObjectEventUserRename::newName => $this->_object->newName,
            DbChatContext::event => Hilos::$db->events[$this->_object->eventId] ?? null,
            DbChatContext::user => Hilos::$db->users[$this->_object->targetUserId] ?? null,
            self::actorUser => $this->_object->actorUserId === null
                ? null
                : (Hilos::$db->users[$this->_object->actorUserId] ?? null),
            default => parent::__get($name),
        };
    }
}
