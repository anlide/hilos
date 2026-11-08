<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ProbeValue as EntityProbeValue;
use Hilos\Database\Object\Collection\ProbeValues as ObjectProbeValues;

/**
 * Scalar cluster database probe row.
 *
 * @extends Object_<EntityProbeValue>
 * @property-read ?int $id
 * @property string $nodeId
 * @property string $key
 * @property string $value
 */
class ProbeValue extends Object_
{
    public const string ENTITY_CLASS = EntityProbeValue::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectProbeValues::class;
    public const string id = 'id';
    public const string nodeId = 'nodeId';
    public const string key = 'key';
    public const string value = 'value';

    /**
     * @param string $property Scalar property name
     * @return mixed Stored field value
     * @throws DatabaseException When the property is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::nodeId => $this->entity->node_id,
            self::key => $this->entity->key,
            self::value => $this->entity->value,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Writable scalar property name
     * @param mixed $value Value to persist
     * @throws DatabaseException When the property cannot be written
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::nodeId => $this->entity->node_id = (string)$value,
            self::key => $this->entity->key = (string)$value,
            self::value => $this->entity->value = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar probe fields */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::nodeId => $this->entity->node_id,
            self::key => $this->entity->key,
            self::value => $this->entity->value,
        ];
    }
}
