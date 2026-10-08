<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\I18nReflow as EntityI18nReflow;
use Hilos\Database\Object\Collection\I18nReflows as ObjectI18nReflows;

/**
 * Scalar record of the catalog fingerprint last taken into the reference tables.
 *
 * The primary key is not generated: the one row is written with its id set.
 *
 * @extends Object_<EntityI18nReflow>
 * @property int $id
 * @property string $fingerprint
 */
class I18nReflow extends Object_
{
    public const string ENTITY_CLASS = EntityI18nReflow::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectI18nReflows::class;
    public const string id = 'id';
    public const string fingerprint = 'fingerprint';

    /**
     * @param string $property Scalar property name
     * @return mixed Stored field value
     * @throws DatabaseException When the property is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::fingerprint => $this->entity->fingerprint,
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
            self::id => $this->entity->id = (int)$value,
            self::fingerprint => $this->entity->fingerprint = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar record fields */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::fingerprint => $this->entity->fingerprint,
        ];
    }
}
