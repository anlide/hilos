<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\Event as ObjectEvent;
use Hilos\Database\Idea\IdeaItem as BaseIdea;
use Hilos\Database\Idea\IdeaCollection;

/**
 * Event Idea
 * High-level abstraction with lazy loading and relationships
 *
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $type
 * @property-read ?string $timestamp
 */
final class Event extends BaseIdea
{
    /** @var self[] Global cache of Event ideas */
    private static array $events = [];

    private ObjectEvent $objectEvent;

    protected function __construct(ObjectEvent &$objectEvent)
    {
        parent::__construct();
        $this->objectEvent = &$objectEvent;
    }

    /**
     * Flush global cache
     */
    public static function flushCache(): void
    {
        self::$events = [];
    }

    /**
     * Get Event idea instance (cached)
     */
    public static function get(ObjectEvent &$objectEvent): self
    {
        $id = $objectEvent->id;

        if (!isset(self::$events[$id])) {
            self::$events[$id] = new self($objectEvent);
        } elseif (self::$events[$id]->objectEvent !== $objectEvent) {
            // Object reference changed, recreate
            self::$events[$id] = new self($objectEvent);
        }

        return self::$events[$id];
    }

    /**
     * Property getter (read-only access)
     */
    public function __get(string $name): IdeaCollection|int|string|bool|null
    {
        return match ($name) {
            ObjectEvent::id => $this->objectEvent->id,
            ObjectEvent::userId => $this->objectEvent->userId,
            ObjectEvent::type => $this->objectEvent->type,
            ObjectEvent::timestamp => $this->objectEvent->timestamp,

            // Example of lazy loading relationships (implement when you have related entities)
            // 'orders' => $this->loadOrders(),
            // 'posts' => $this->loadPosts(),

            default => throw new \Exception("Property [{$name}] does not exist on IdeaEvent"),
        };
    }

    /**
     * Convert to array
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectEvent::id] = $this->objectEvent->id;
        }

        $data[ObjectEvent::userId] = $this->objectEvent->userId;
        $data[ObjectEvent::type] = $this->objectEvent->type;
        $data[ObjectEvent::timestamp] = $this->objectEvent->timestamp;

        return $data;
    }
}
