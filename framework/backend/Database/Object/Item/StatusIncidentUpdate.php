<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusIncidentUpdate as EntityStatusIncidentUpdate;
use Hilos\Database\Object\Collection\StatusIncidentUpdates as ObjectStatusIncidentUpdates;
use Hilos\Database\Exception\PropertyNotAccessibleException;

/**
 * @extends Object_<EntityStatusIncidentUpdate>
 * @property ?int $id
 * @property int $incident_id
 * @property string $kind
 * @property string $severity
 * @property string $text
 * @property int $at
 * @property ?string $request_id
 */
class StatusIncidentUpdate extends Object_
{
    public const string ENTITY_CLASS = EntityStatusIncidentUpdate::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusIncidentUpdates::class;

    public const string id = 'id';
    public const string incident_id = 'incident_id';
    public const string kind = 'kind';
    public const string severity = 'severity';
    public const string text = 'text';
    public const string at = 'at';
    public const string request_id = 'request_id';

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
            self::incident_id => $this->entity->incident_id,
            self::kind => $this->entity->kind,
            self::severity => $this->entity->severity,
            self::text => $this->entity->text,
            self::at => $this->entity->at,
            self::request_id => $this->entity->request_id,
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
            self::incident_id => $this->entity->incident_id = $value,
            self::kind => $this->entity->kind = $value,
            self::severity => $this->entity->severity = $value,
            self::text => $this->entity->text = $value,
            self::at => $this->entity->at = $value,
            self::request_id => $this->entity->request_id = $value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar storage row */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::incident_id => $this->entity->incident_id,
            self::kind => $this->entity->kind,
            self::severity => $this->entity->severity,
            self::text => $this->entity->text,
            self::at => $this->entity->at,
            self::request_id => $this->entity->request_id,
        ];
    }
}
