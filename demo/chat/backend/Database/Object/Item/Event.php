<?php

namespace Demo\Chat\Database\Object\Item;

use Hilos\Database\Object\Item\Object_;
use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Hilos\Exception\DatabaseException;

/**
 * Event Object
 * Auto-generated from Entity: Demo\Chat\Database\Entity\Item\Event
 *
 * @property-read ?int $id
 * @property ?int $userId
 * @property string $type
 * @property string $timestamp
 * @property ?string $data
 */
final class Event extends Object_
{
    public const string id = 'id';
    public const string userId = 'userId';
    public const string type = 'type';
    public const string timestamp = 'timestamp';
    public const string data = 'data';

    protected EntityEvent $entity;
    protected EntityEvent $entitySync;

    public static function create(): self
    {
        $obj = new self();
        $obj->entity = EntityEvent::getEmpty();
        $obj->entitySync = clone $obj->entity;
        return $obj;
    }

    public static function fromEntity(EntityEvent $entity): self
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
            self::type => $this->entity->type,
            self::timestamp => $this->entity->timestamp,
            self::data => $this->entity->data,
            default => parent::__get($property),
        };
    }

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = $value === null ? null : (int)$value,
            self::type => $this->entity->type = (string)$value,
            self::timestamp => $this->entity->timestamp = (string)$value,
            self::data => $this->entity->data = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }

    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::type => $this->entity->type,
            self::timestamp => $this->entity->timestamp,
            self::data => $this->entity->data,
        ];
    }

    public function getIdString(): string
    {
        if ($this->entity->id === null) {
            throw new DatabaseException("Cannot get ID string: Event ID is null");
        }
        return (string)$this->entity->id;
    }
}
