<?php

namespace Demo\Chat\Hilos\Database\Item;

use Demo\Chat\Database\Object\Event as ObjectEvent;
use Hilos\Hilos\Database\Item\DbItem;
use RuntimeException;

/**
 * Event Db item - high-level abstraction with lazy loading and relationships.
 *
 * @extends DbItem<ObjectEvent>
 */
final class Event extends DbItem
{
    public function __construct(ObjectEvent &$objectEvent)
    {
        parent::__construct($objectEvent);
    }

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

    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        $data = [];
        if ($withId) {
            $data[ObjectEvent::id] = $this->_object->id;
        }
        $data[ObjectEvent::userId] = $this->_object->userId;
        $data[ObjectEvent::type] = $this->_object->type;
        $data[ObjectEvent::timestamp] = $this->_object->timestamp;
        $data[ObjectEvent::data] = $this->_object->data;
        return $data;
    }
}
