<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StepUp as EntityStepUp;

/**
 * StepUp object - wraps one operation confirmation (HIL-495).
 *
 * @extends Object_<EntityStepUp>
 *
 * @property-read ?int $id
 * @property string $sessionTokenHash
 * @property int $userId
 * @property string $operation
 * @property string $confirmedUntil
 * @property string $createdAt
 */
final class StepUp extends Object_
{
    public const string ENTITY_CLASS = EntityStepUp::class;
    public const string id = 'id';
    public const string sessionTokenHash = 'sessionTokenHash';
    public const string userId = 'userId';
    public const string operation = 'operation';
    public const string confirmedUntil = 'confirmedUntil';
    public const string createdAt = 'createdAt';

    /**
     * @return string Collection key (HilosDbContext::stepUps)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::stepUps;
    }

    /**
     * @param string $property Property name (see class @property list)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known StepUp field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::sessionTokenHash => $this->entity->session_token_hash,
            self::userId => $this->entity->user_id,
            self::operation => $this->entity->operation,
            self::confirmedUntil => $this->entity->confirmed_until,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Settable property name (see class @property list)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a StepUp
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::sessionTokenHash => $this->entity->session_token_hash = (string)$value,
            self::userId => $this->entity->user_id = (int)$value,
            self::operation => $this->entity->operation = (string)$value,
            self::confirmedUntil => $this->entity->confirmed_until = (string)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * @return array<string, mixed> Confirmation data
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::sessionTokenHash => $this->entity->session_token_hash,
            self::userId => $this->entity->user_id,
            self::operation => $this->entity->operation,
            self::confirmedUntil => $this->entity->confirmed_until,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
