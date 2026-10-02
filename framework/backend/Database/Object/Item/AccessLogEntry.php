<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\AccessLogEntry as EntityAccessLogEntry;

/**
 * AccessLogEntry object - wraps one use of an account (HIL-1174).
 *
 * @extends Object_<EntityAccessLogEntry>
 *
 * @property-read ?int $id
 * @property int $userId
 * @property string $event
 * @property ?string $ipAddress
 * @property string $occurredAt
 */
class AccessLogEntry extends Object_
{
    public const string ENTITY_CLASS = EntityAccessLogEntry::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string event = 'event';
    public const string ipAddress = 'ipAddress';
    public const string occurredAt = 'occurredAt';

    /**
     * @return string Collection key (HilosDbContext::accessLogEntries)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::accessLogEntries;
    }

    /**
     * @param string $property Property name (see class @property list)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known AccessLogEntry field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::event => $this->entity->event,
            self::ipAddress => $this->entity->ip_address,
            self::occurredAt => $this->entity->occurred_at,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Settable property name (see class @property list)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on an AccessLogEntry
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::event => $this->entity->event = (string)$value,
            self::ipAddress => $this->entity->ip_address = is_scalar($value) ? (string)$value : null,
            self::occurredAt => $this->entity->occurred_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * @return array<string, mixed> Access log row data
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::event => $this->entity->event,
            self::ipAddress => $this->entity->ip_address,
            self::occurredAt => $this->entity->occurred_at,
        ];
    }
}
