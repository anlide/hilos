<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\Object\Collection\StatusIncidents as ObjectStatusIncidents;
use Hilos\Database\Exception\PropertyNotAccessibleException;

/**
 * @extends Object_<EntityStatusIncident>
 * @property ?int $id
 * @property string $component_key
 * @property string $component_name
 * @property ?string $active_component_key
 * @property string $severity
 * @property ?string $peak_severity
 * @property int $detected_at
 * @property int $publish_at
 * @property ?int $published_at
 * @property ?int $suppressed_at
 * @property ?int $resolved_at
 * @property ?string $outcome
 */
class StatusIncident extends Object_
{
    public const string ENTITY_CLASS = EntityStatusIncident::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusIncidents::class;

    public const string id = 'id';
    public const string component_key = 'component_key';
    public const string component_name = 'component_name';
    public const string active_component_key = 'active_component_key';
    public const string severity = 'severity';
    public const string peak_severity = 'peak_severity';
    public const string detected_at = 'detected_at';
    public const string publish_at = 'publish_at';
    public const string published_at = 'published_at';
    public const string suppressed_at = 'suppressed_at';
    public const string resolved_at = 'resolved_at';
    public const string outcome = 'outcome';

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
            self::component_name => $this->entity->component_name,
            self::active_component_key => $this->entity->active_component_key,
            self::severity => $this->entity->severity,
            self::peak_severity => $this->entity->peak_severity,
            self::detected_at => $this->entity->detected_at,
            self::publish_at => $this->entity->publish_at,
            self::published_at => $this->entity->published_at,
            self::suppressed_at => $this->entity->suppressed_at,
            self::resolved_at => $this->entity->resolved_at,
            self::outcome => $this->entity->outcome,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Scalar storage field
     * @param mixed $value Value with the field's declared type
     * @throws DatabaseException When the property is not writable
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::component_key => $this->entity->component_key = $value,
            self::component_name => $this->entity->component_name = $value,
            self::active_component_key => $this->entity->active_component_key = $value,
            self::severity => $this->entity->severity = $value,
            self::peak_severity => $this->entity->peak_severity = $value,
            self::detected_at => $this->entity->detected_at = $value,
            self::publish_at => $this->entity->publish_at = $value,
            self::published_at => $this->entity->published_at = $value,
            self::suppressed_at => $this->entity->suppressed_at = $value,
            self::resolved_at => $this->entity->resolved_at = $value,
            self::outcome => $this->entity->outcome = $value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar storage row */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::component_key => $this->entity->component_key,
            self::component_name => $this->entity->component_name,
            self::active_component_key => $this->entity->active_component_key,
            self::severity => $this->entity->severity,
            self::peak_severity => $this->entity->peak_severity,
            self::detected_at => $this->entity->detected_at,
            self::publish_at => $this->entity->publish_at,
            self::published_at => $this->entity->published_at,
            self::suppressed_at => $this->entity->suppressed_at,
            self::resolved_at => $this->entity->resolved_at,
            self::outcome => $this->entity->outcome,
        ];
    }
}
