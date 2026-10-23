<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\StatusIncidentUpdate as ObjectStatusIncidentUpdate;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectStatusIncidentUpdate>
 * @method __construct(ObjectStatusIncidentUpdate $object)
 * @property-read ?int $id Stored id
 * @property-read int $incidentId Stored incident_id
 * @property-read string $kind Stored kind
 * @property-read string $severity Stored severity
 * @property-read string $text Stored text
 * @property-read int $at Stored at
 * @property-read ?string $requestId Stored request_id
 * @property-read ?StatusIncident $incident Direct parent incident
 */
class StatusIncidentUpdate extends DbItem
{
    public const string incident = 'incident';

    public const string id = 'id';
    public const string incidentId = 'incidentId';
    public const string kind = 'kind';
    public const string severity = 'severity';
    public const string text = 'text';
    public const string at = 'at';
    public const string requestId = 'requestId';

    /**
     * @param string $name View field or direct relation
     * @return mixed Scalar field, relation or inherited property
     * @throws HilosException When a relation or inherited property cannot be read
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::id => $this->_object->id,
            self::incidentId => $this->_object->incident_id,
            self::kind => $this->_object->kind,
            self::severity => $this->_object->severity,
            self::text => $this->_object->text,
            self::at => $this->_object->at,
            self::requestId => $this->_object->request_id,
            self::incident => Hilos::$db->statusIncidents[$this->_object->incident_id] ?? null,
            default => parent::__get($name),
        };
    }

    /**
     * Relations stay access-only, matching the base scalar serialization contract.
     *
     * @param bool $withId Include the primary id
     * @param bool $idAsIndex Reserved base flag
     * @param bool $withBridges Reserved base flag; relations are not serialized
     * @param bool $withCalculation Reserved base flag; no calculated fields exist
     * @param bool $toFrontend Include the primary id regardless of withId
     * @return array<string, mixed> Scalar camelCase View fields
     */
    public function toArray(
        bool $withId = true,
        bool $idAsIndex = true,
        bool $withBridges = false,
        bool $withCalculation = false,
        bool $toFrontend = false,
    ): array {
        $fields = [
            self::id => $this->_object->id,
            self::incidentId => $this->_object->incident_id,
            self::kind => $this->_object->kind,
            self::severity => $this->_object->severity,
            self::text => $this->_object->text,
            self::at => $this->_object->at,
            self::requestId => $this->_object->request_id,
        ];
        if (!$withId && !$toFrontend) {
            unset($fields[self::id]);
        }
        return $fields;
    }
}
