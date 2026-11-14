<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusObservedComponent as EntityStatusObservedComponent;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Collection\StatusObservedComponents as ObjectStatusObservedComponents;

/**
 * @extends Object_<EntityStatusObservedComponent>
 * @property ?int $id Internal primary id
 * @property string $component_key Status component key
 * @property int $observed_since Start of observation in signed UTC epoch milliseconds
 */
class StatusObservedComponent extends Object_
{
    public const string ENTITY_CLASS = EntityStatusObservedComponent::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusObservedComponents::class;

    public const string id = 'id';
    public const string component_key = 'component_key';
    public const string observed_since = 'observed_since';

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
            self::component_key => $this->entity->component_key,
            self::observed_since => $this->entity->observed_since,
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
            self::component_key => $this->entity->component_key = $value,
            self::observed_since => $this->entity->observed_since = $value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar storage row */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::component_key => $this->entity->component_key,
            self::observed_since => $this->entity->observed_since,
        ];
    }
}
