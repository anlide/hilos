<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\Event as ObjectEvent;
use Hilos\Database\Idea\IdeaItem;
use RuntimeException;

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
     *
     * @param string $name Property name
     * @return int|string|null Property value
     * @throws RuntimeException If property does not exist
     */
    public function __get(string $name): int|string|null
    {
        return match ($name) {
            ObjectEvent::id => $this->_object->id,
            ObjectEvent::userId => $this->_object->userId,
            ObjectEvent::type => $this->_object->type,
            ObjectEvent::timestamp => $this->_object->timestamp,

            default => parent::__get($name),
        };
    }

    /**
     * Convert to array representation
     *
     * @param bool $withId Include ID field in result
     * @param bool $idAsIndex Use ID as array key
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @return array<string, mixed> Array representation
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
