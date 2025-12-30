<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\Event as ObjectEvent;
use Exception;
use Hilos\Database\Idea\IdeaItem;
use Hilos\Database\Idea\IdeaCollection;

/**
 * Event Idea
 * High-level abstraction with lazy loading and relationships
 *
 * @extends IdeaItem<ObjectEvent>
 *
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $type
 * @property-read ?string $timestamp
 */
final class Event extends IdeaItem
{
    /**
     * Public constructor - creates IdeaEvent from ObjectEvent instance
     *
     * @param ObjectEvent $objectEvent ObjectEvent instance (reference)
     */
    protected function __construct(ObjectEvent &$objectEvent)
    {
        parent::__construct($objectEvent);
    }

    /**
     * Property getter (read-only access)
     * @throws Exception
     */
    public function __get(string $name): IdeaCollection|int|string|bool|null
    {
        return match ($name) {
            ObjectEvent::id => $this->_object->id,
            ObjectEvent::userId => $this->_object->userId,
            ObjectEvent::type => $this->_object->type,
            ObjectEvent::timestamp => $this->_object->timestamp,

            default => throw new Exception("Property [{$name}] does not exist on IdeaEvent"),
        };
    }

    /**
     * Convert to array
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectEvent::id] = $this->_object->id;
        }

        $data[ObjectEvent::userId] = $this->_object->userId;
        $data[ObjectEvent::type] = $this->_object->type;
        $data[ObjectEvent::timestamp] = $this->_object->timestamp;

        return $data;
    }
}
