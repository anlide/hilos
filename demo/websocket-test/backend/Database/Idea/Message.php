<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\Message as ObjectMessage;
use Hilos\Database\Idea\IdeaObject as BaseIdea;
use Hilos\Database\Idea\IdeaCollection;

/**
 * Message Idea
 * High-level abstraction with lazy loading and relationships
 *
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $message
 * @property-read ?string $timestamp
 */
final class Message extends BaseIdea
{
    /** @var self[] Global cache of Message ideas */
    private static array $messages = [];

    private ObjectMessage $objectMessage;

    protected function __construct(ObjectMessage &$objectMessage)
    {
        parent::__construct();
        $this->objectMessage = &$objectMessage;
    }

    /**
     * Flush global cache
     */
    public static function flushCache(): void
    {
        self::$messages = [];
    }

    /**
     * Get Message idea instance (cached)
     */
    public static function get(ObjectMessage &$objectMessage): self
    {
        $id = $objectMessage->id;

        if (!isset(self::$messages[$id])) {
            self::$messages[$id] = new self($objectMessage);
        } elseif (self::$messages[$id]->objectMessage !== $objectMessage) {
            // Object reference changed, recreate
            self::$messages[$id] = new self($objectMessage);
        }

        return self::$messages[$id];
    }

    /**
     * Property getter (read-only access)
     */
    public function __get(string $name): IdeaCollection|int|string|bool|null
    {
        return match ($name) {
            ObjectMessage::id => $this->objectMessage->id,
            ObjectMessage::userId => $this->objectMessage->userId,
            ObjectMessage::message => $this->objectMessage->message,
            ObjectMessage::timestamp => $this->objectMessage->timestamp,

            // Example of lazy loading relationships (implement when you have related entities)
            // 'orders' => $this->loadOrders(),
            // 'posts' => $this->loadPosts(),

            default => throw new \Exception("Property [{$name}] does not exist on IdeaMessage"),
        };
    }

    /**
     * Convert to array
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectMessage::id] = $this->objectMessage->id;
        }

        $data[ObjectMessage::userId] = $this->objectMessage->userId;
        $data[ObjectMessage::message] = $this->objectMessage->message;
        $data[ObjectMessage::timestamp] = $this->objectMessage->timestamp;

        return $data;
    }
}
