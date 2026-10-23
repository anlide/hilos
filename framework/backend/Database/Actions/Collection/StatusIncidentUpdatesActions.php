<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Object\Collection\StatusIncidentUpdates as ObjectStatusIncidentUpdates;
use Hilos\Database\View\Collection\StatusIncidentUpdates as DbCollectionStatusIncidentUpdates;
use Hilos\Database\View\Item\StatusIncidentUpdate;
use Hilos\HilosException;
use Hilos\Status\Exception\StatusIncidentWriteException;
use Hilos\Status\StatusSeverity;
use Hilos\Status\StatusUpdateKind;
use Hilos\Status\StatusUpdateText;

/**
 * Appends immutable rows in the caller's transaction. The owner judges the incident's state.
 *
 * @extends DbActions<StatusIncidentUpdate, ObjectStatusIncidentUpdates>
 * @property-read DbCollectionStatusIncidentUpdates $collection
 * @property-read ObjectStatusIncidentUpdates $objectCollection
 */
class StatusIncidentUpdatesActions extends DbActions
{
    private const int MAX_REQUEST_ID_LENGTH = 128;

    /**
     * Replaying a team request returns its row; a different text cannot replace that row.
     * Automatic updates never carry a request id; null team ids are separate submissions.
     *
     * @param int $incidentId Persisted parent primary id
     * @param StatusUpdateKind $kind Automatic transition or team submission
     * @param StatusSeverity $severity Severity recorded with this update
     * @param StatusUpdateText $text Normalized plain text
     * @param int $at Update moment in UTC epoch milliseconds
     * @param ?string $requestId Team request correlation id, or null for an untracked update
     * @return StatusIncidentUpdate New row or the original row of a replayed request
     * @throws StatusIncidentWriteException When input is invalid or a replay changes the saved text
     * @throws HilosException When ownership, lookup or persistence refuses the append
     */
    public function append(
        int $incidentId,
        StatusUpdateKind $kind,
        StatusSeverity $severity,
        StatusUpdateText $text,
        int $at,
        ?string $requestId = null,
    ): StatusIncidentUpdate {
        $this->ensureCanCreateInSet((string)$incidentId);
        if ($incidentId <= 0 || $at < 0) {
            throw new StatusIncidentWriteException('Choose an existing incident and a valid update time.');
        }
        if ($requestId !== null) {
            if ($kind !== StatusUpdateKind::TEAM || !mb_check_encoding($requestId, 'UTF-8') ||
                mb_strlen($requestId, 'UTF-8') > self::MAX_REQUEST_ID_LENGTH) {
                throw new StatusIncidentWriteException('The update request is invalid.');
            }
            $previous = $this->collection->forRequest($incidentId, $requestId);
            if ($previous !== null) {
                if ($previous->text !== $text->value) {
                    throw new StatusIncidentWriteException('This update request already contains different text.');
                }
                return $previous;
            }
        }

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $update = $objectClass::create();
        $update->incident_id = $incidentId;
        $update->kind = $kind->value;
        $update->severity = $severity->value;
        $update->text = $text->value;
        $update->at = $at;
        $update->request_id = $requestId;
        $update->sync();
        $this->addObjectToCollection($update);

        return $this->createDbItemFromObject($update);
    }
}
