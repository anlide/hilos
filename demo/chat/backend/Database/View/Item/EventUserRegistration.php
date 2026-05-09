<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\EventUserRegistration as ObjectEventUserRegistration;
use Demo\Chat\Hilos;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * EventUserRegistration - Db item for registration event details.
 *
 * @extends DbItem<ObjectEventUserRegistration>
 * @method __construct(ObjectEventUserRegistration &$objectEventUserRegistration)
 *
 * @property-read int $eventId
 * @property-read int $targetUserId
 * @property-read ?Event $event Parent chat event
 * @property-read ?User $user Registered user
 */
final class EventUserRegistration extends DbItem
{
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
            ObjectEventUserRegistration::eventId => $this->_object->eventId,
            ObjectEventUserRegistration::targetUserId => $this->_object->targetUserId,
            DbChatContext::event => Hilos::$db->events[$this->_object->eventId] ?? null,
            DbChatContext::user => Hilos::$db->users[$this->_object->targetUserId] ?? null,
            default => parent::__get($name),
        };
    }
}
