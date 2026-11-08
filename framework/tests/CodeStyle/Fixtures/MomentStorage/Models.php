<?php

declare(strict_types=1);

namespace MomentFixtures;

class EventEntity
{
    public const string at = 'at';
    public const string created_at = 'created_at';
    public const string birthday = 'birthday';
    public const string duration = 'duration';
}

class OtherEntity
{
}

class EventObject
{
    public const string ENTITY_CLASS = EventEntity::class;
    public const string happenedAt = 'happenedAt';
    public const string misleadingAt = 'misleadingAt';

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::happenedAt => $this->entity->at = (int)$value,
            self::misleadingAt => $this->entity->duration = (int)$value,
            default => null,
        };
    }

    public function directEntity(): void
    {
        $this->entity->created_at = time();
    }
}

class ChildObject extends EventObject
{
}

class ReadOnlyObject
{
    public const string ENTITY_CLASS = EventEntity::class;

    public function __get(string $property): mixed
    {
        return match ($property) {
            'happenedAt' => $this->entity->at,
            default => null,
        };
    }
}

class UnknownMapping
{
    public const string ENTITY_CLASS = EventEntity::class;

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            'happenedAt' => $this->convert($value),
            default => null,
        };
    }
}

class EventCollection
{
    public const string OBJECT_CLASS = EventObject::class;
}

class FakeClock
{
    public function getTimestamp(): int
    {
        return 17;
    }

    public static function getSqlDateTime(): string
    {
        return 'not a date';
    }
}
