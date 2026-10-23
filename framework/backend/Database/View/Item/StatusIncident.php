<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\StatusIncident as ObjectStatusIncident;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Database\Actions\Item\StatusIncidentActions;
use Hilos\Database\View\Collection\StatusIncidentUpdates;
use Hilos\Database\Entity\Item\StatusIncidentUpdate as EntityStatusIncidentUpdate;

/**
 * @extends DbItem<ObjectStatusIncident>
 * @method __construct(ObjectStatusIncident $object)
 * @property-read ?int $id Stored id
 * @property-read string $componentKey Stored component_key
 * @property-read string $componentName Stored component_name
 * @property-read ?string $activeComponentKey Stored active_component_key
 * @property-read string $severity Stored severity
 * @property-read ?string $peakSeverity Stored peak_severity
 * @property-read int $detectedAt Stored detected_at
 * @property-read int $publishAt Stored publish_at
 * @property-read ?int $publishedAt Stored published_at
 * @property-read ?int $suppressedAt Stored suppressed_at
 * @property-read ?int $resolvedAt Stored resolved_at
 * @property-read ?string $outcome Stored outcome
 * @property-read StatusIncidentActions $actions Incident mutations
 * @property-read StatusIncidentUpdates $updates Immutable timeline for this incident id
 */
class StatusIncident extends DbItem
{
    public const string updates = 'updates';

    public const string id = 'id';
    public const string componentKey = 'componentKey';
    public const string componentName = 'componentName';
    public const string activeComponentKey = 'activeComponentKey';
    public const string severity = 'severity';
    public const string peakSeverity = 'peakSeverity';
    public const string detectedAt = 'detectedAt';
    public const string publishAt = 'publishAt';
    public const string publishedAt = 'publishedAt';
    public const string suppressedAt = 'suppressedAt';
    public const string resolvedAt = 'resolvedAt';
    public const string outcome = 'outcome';

    /**
     * @param string $name View field or direct relation
     * @return mixed Scalar field, relation or inherited property
     * @throws HilosException When a relation or inherited property cannot be read
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::id => $this->_object->id,
            self::componentKey => $this->_object->component_key,
            self::componentName => $this->_object->component_name,
            self::activeComponentKey => $this->_object->active_component_key,
            self::severity => $this->_object->severity,
            self::peakSeverity => $this->_object->peak_severity,
            self::detectedAt => $this->_object->detected_at,
            self::publishAt => $this->_object->publish_at,
            self::publishedAt => $this->_object->published_at,
            self::suppressedAt => $this->_object->suppressed_at,
            self::resolvedAt => $this->_object->resolved_at,
            self::outcome => $this->_object->outcome,
            self::updates => Hilos::$db->statusIncidentUpdates->whereColumnIs(EntityStatusIncidentUpdate::incident_id, $this->_object->id),
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
            self::componentKey => $this->_object->component_key,
            self::componentName => $this->_object->component_name,
            self::activeComponentKey => $this->_object->active_component_key,
            self::severity => $this->_object->severity,
            self::peakSeverity => $this->_object->peak_severity,
            self::detectedAt => $this->_object->detected_at,
            self::publishAt => $this->_object->publish_at,
            self::publishedAt => $this->_object->published_at,
            self::suppressedAt => $this->_object->suppressed_at,
            self::resolvedAt => $this->_object->resolved_at,
            self::outcome => $this->_object->outcome,
        ];
        if (!$withId && !$toFrontend) {
            unset($fields[self::id]);
        }
        return $fields;
    }
}
