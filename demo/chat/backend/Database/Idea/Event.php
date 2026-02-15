<?php

namespace Demo\Chat\Database\Idea;

use Demo\Chat\Database\Object\Event as ObjectEvent;
use Hilos\Hilos\Database\DbItem;
use Hilos\Exception\Idea\Item\IdeaItemPropertyNotFoundException;
use RuntimeException;

/**
 * Event Idea
 * @deprecated Use Demo\Chat\Hilos\Database\Db\Event.
 * High-level abstraction with lazy loading and relationships
 *
 * @extends DbItem<ObjectEvent>
 *
 * @property-read ?int $id
 * @property-read ?int $userId
 * @property-read string $type
 * @property-read string $timestamp
 * @property-read ?string $data
 */
final class Event extends DbItem
{
    /**
     * Public constructor - creates IdeaEvent from ObjectEvent instance
     *
     * @param ObjectEvent $objectEvent ObjectEvent instance (reference)
     */
    public function __construct(ObjectEvent &$objectEvent)
    {
        parent::__construct($objectEvent);
    }

    /**
     * Property getter (read-only access)
     *
     * @param string $name Property name
     * @return int|string|null Property value
     * @throws RuntimeException If property does not exist
     * @throws IdeaItemPropertyNotFoundException If property is not defined in IdeaItem
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

    /**
     * Convert to array representation
     *
     * @param bool $withId Include ID field in result
     * @param bool $idAsIndex Use ID as array key
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend When true, exclude fields that must not be sent to frontend
     * @return array<string, mixed> Array representation
     */
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
