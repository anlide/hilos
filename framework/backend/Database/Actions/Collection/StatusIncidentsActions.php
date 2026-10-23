<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Object\Collection\StatusIncidents as ObjectStatusIncidents;
use Hilos\Database\View\Collection\StatusIncidents as DbCollectionStatusIncidents;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\HilosException;
use Hilos\Status\Exception\StatusIncidentWriteException;
use Hilos\Status\StatusSeverity;

/**
 * Joins the caller's transaction; the owner combines the incident and timeline there.
 *
 * @extends DbActions<StatusIncident, ObjectStatusIncidents>
 * @property-read DbCollectionStatusIncidents $collection
 * @property-read ObjectStatusIncidents $objectCollection
 */
class StatusIncidentsActions extends DbActions
{
    private const int MAX_COMPONENT_KEY_LENGTH = 128;
    private const int MAX_COMPONENT_NAME_LENGTH = 255;

    /**
     * @param string $componentKey Code-catalog key, not a persisted entity reference
     * @param string $componentName Snapshot of the public component name
     * @param StatusSeverity $severity Current abnormal severity
     * @param int $detectedAt Detection moment in UTC epoch milliseconds
     * @param int $publishAt Publication deadline in UTC epoch milliseconds
     * @return StatusIncident Newly persisted candidate with no public peak yet
     * @throws StatusIncidentWriteException When the component or moments are invalid
     * @throws HilosException When ownership or persistence refuses creation
     */
    public function candidate(
        string $componentKey,
        string $componentName,
        StatusSeverity $severity,
        int $detectedAt,
        int $publishAt,
    ): StatusIncident {
        $this->ensureCanCreate();
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/D', $componentKey) || strlen($componentKey) > self::MAX_COMPONENT_KEY_LENGTH) {
            throw new StatusIncidentWriteException('Choose a valid status component.');
        }
        if (!mb_check_encoding($componentName, 'UTF-8') || trim($componentName) === '' ||
            mb_strlen($componentName, 'UTF-8') > self::MAX_COMPONENT_NAME_LENGTH) {
            throw new StatusIncidentWriteException('Enter a component name of 1 to 255 characters.');
        }
        if ($detectedAt < 0 || $publishAt < $detectedAt) {
            throw new StatusIncidentWriteException('The publication deadline must be at or after detection.');
        }

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $incident = $objectClass::create();
        $incident->component_key = $componentKey;
        $incident->component_name = $componentName;
        $incident->active_component_key = $componentKey;
        $incident->severity = $severity->value;
        $incident->detected_at = $detectedAt;
        $incident->publish_at = $publishAt;
        $incident->sync();
        $this->addObjectToCollection($incident);

        return $this->createDbItemFromObject($incident);
    }
}
