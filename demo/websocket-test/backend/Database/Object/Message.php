<?php

namespace Demo\WebSocketTest\Database\Object;

use Hilos\Database\Object\Object_;
use Demo\WebSocketTest\Database\Entity\Message as EntityMessage;

/**
 * Message Object
 * Auto-generated from Entity: Demo\WebSocketTest\Database\Entity\Message
 *
 * @property-read ?int $id
 * @property int $userId
 * @property string $message
 * @property ?string $timestamp
 */
final class Message extends Object_
{
    // Property name constants (camelCase for PHP)
    public const string id = 'id';
    public const string userId = 'userId';
    public const string message = 'message';
    public const string timestamp = 'timestamp';

    protected EntityMessage $entity;
    protected EntityMessage $entitySync;

    public static function create(): self
    {
        $obj = new self();
        $obj->entity = EntityMessage::getEmpty();
        $obj->entitySync = clone $obj->entity;
        return $obj;
    }

    public static function fromEntity(EntityMessage $entity): self
    {
        $obj = new self();
        $obj->entity = $entity;
        $obj->entitySync = clone $entity;
        return $obj;
    }

    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::message => $this->entity->message,
            self::timestamp => $this->entity->timestamp,
            default => parent::__get($property),
        };
    }

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::message => $this->entity->message = (string)$value,
            self::timestamp => $this->entity->timestamp = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::message => $this->entity->message,
            self::timestamp => $this->entity->timestamp,
        ];
    }
}
