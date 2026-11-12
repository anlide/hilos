<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusSilencePeriod as EntityStatusSilencePeriod;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Collection\StatusSilencePeriods as ObjectStatusSilencePeriods;

/**
 * @extends Object_<EntityStatusSilencePeriod>
 * @property ?int $id Internal primary id
 * @property string $public_id Opaque publication identity
 * @property int $started_at Gap start in signed UTC epoch milliseconds
 * @property int $ended_at Exclusive gap end in signed UTC epoch milliseconds
 */
class StatusSilencePeriod extends Object_
{
    public const string ENTITY_CLASS = EntityStatusSilencePeriod::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusSilencePeriods::class;

    public const string id = 'id';
    public const string public_id = 'public_id';
    public const string started_at = 'started_at';
    public const string ended_at = 'ended_at';

    /**
     * @param string $property Scalar storage field
     * @return mixed Stored value or inherited property
     * @throws DatabaseException When the property is unknown
     * @throws PropertyNotAccessibleException When the inherited property is inaccessible
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::public_id => $this->entity->public_id,
            self::started_at => $this->entity->started_at,
            self::ended_at => $this->entity->ended_at,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Scalar storage field
     * @param mixed $value Value with the declared field type
     * @throws DatabaseException When the property is not writable
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::public_id => $this->entity->public_id = $value,
            self::started_at => $this->entity->started_at = $value,
            self::ended_at => $this->entity->ended_at = $value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar storage row */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::public_id => $this->entity->public_id,
            self::started_at => $this->entity->started_at,
            self::ended_at => $this->entity->ended_at,
        ];
    }
}
