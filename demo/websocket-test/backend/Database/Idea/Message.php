<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\Message as ObjectMessage;
use Hilos\Database\Idea\IdeaItem as BaseIdea;
use Hilos\Database\Idea\IdeaCollection;

/**
 * Message Idea
 * High-level abstraction with lazy loading and relationships
 *
 * @extends BaseIdea<ObjectMessage>
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

    protected function __construct(ObjectMessage &$objectMessage)
    {
        parent::__construct($objectMessage);
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
        } elseif (self::$messages[$id]->_object !== $objectMessage) {
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
            ObjectMessage::id => $this->_object->id,
            ObjectMessage::userId => $this->_object->userId,
            ObjectMessage::message => $this->_object->message,
            ObjectMessage::timestamp => $this->_object->timestamp,

            default => parent::__get($name),
        };
    }

    /**
     * Convert to array
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectMessage::id] = $this->_object->id;
        }

        $data[ObjectMessage::userId] = $this->_object->userId;
        $data[ObjectMessage::message] = $this->_object->message;
        $data[ObjectMessage::timestamp] = $this->_object->timestamp;

        return $data;
    }
}
