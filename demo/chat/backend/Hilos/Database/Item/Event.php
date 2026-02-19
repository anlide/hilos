<?php

namespace Demo\Chat\Hilos\Database\Item;

use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Hilos\Hilos\Database\Exception\Item\PropertyNotFoundException;
use Hilos\Hilos\Database\Item\DbItem;

/**
 * Event Db item - high-level abstraction with lazy loading and relationships.
 *
 * @extends DbItem<ObjectEvent>
 * @method __construct(ObjectEvent &$objectEvent)
 */
final class Event extends DbItem
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
            ObjectEvent::id => $this->_object->id,
            ObjectEvent::userId => $this->_object->userId,
            ObjectEvent::type => $this->_object->type,
            ObjectEvent::timestamp => $this->_object->timestamp,
            ObjectEvent::data => $this->_object->data,
            default => parent::__get($name),
        };
    }
}
