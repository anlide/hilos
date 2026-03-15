<?php

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Hilos;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * Event Db item - high-level abstraction with lazy loading and relationships.
 *
 * @extends DbItem<ObjectEvent>
 * @method __construct(ObjectEvent &$objectEvent)
 *
 * @property-read ?int $id
 * @property-read ?int $userId
 * @property-read ?int $botId
 * @property-read string $type
 * @property-read string $timestamp
 * @property-read ?string $data
 * @property-read ?User $user User for this event (null if userId is null or user not found)
 * @property-read ?Bot $bot Bot for this event (null if botId is null or bot not found)
 */
final class Event extends DbItem
{
    /**
     * Property getter (read-only access). Supports lazy loading of related items.
     *
     * @param string $name Property name
     * @return int|string|User|Bot|null Property value or User/Bot for relationships
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If collection actions class is not defined for related item
     */
    public function __get(string $name): int|string|User|Bot|null
    {
        return match ($name) {
            ObjectEvent::id => $this->_object->id,
            ObjectEvent::userId => $this->_object->userId,
            ObjectEvent::botId => $this->_object->botId,
            ObjectEvent::type => $this->_object->type,
            ObjectEvent::timestamp => $this->_object->timestamp,
            ObjectEvent::data => $this->_object->data,
            DbChatContext::user => $this->_object->userId !== null ? (Hilos::$db->users[$this->_object->userId] ?? null) : null,
            DbChatContext::bot => $this->_object->botId !== null ? (Hilos::$db->bots[$this->_object->botId] ?? null) : null,
            default => parent::__get($name),
        };
    }
}
