<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Database\Object\Item\StatusIncident as ObjectStatusIncident;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\HilosException;
use Hilos\Status\Exception\StatusIncidentWriteException;
use Hilos\Status\StatusIncidentOutcome;
use Hilos\Status\StatusSeverity;

/**
 * Incident mutations join the caller's transaction; the owner appends its timeline there.
 *
 * @extends DbActions<StatusIncident, ObjectStatusIncident>
 * @property-read ObjectStatusIncident $object
 */
class StatusIncidentActions extends DbActions
{
    /**
     * @param int $at Suppression moment in UTC epoch milliseconds
     * @throws StatusIncidentWriteException When the incident is published, ended, or the time precedes detection
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function suppress(int $at): void
    {
        $this->ensureCanWrite();
        $this->assertOpen();
        $this->assertMoment($at);
        if ($this->object->published_at !== null) {
            throw new StatusIncidentWriteException('This incident has already been published.');
        }
        if ($this->object->suppressed_at !== null) {
            return;
        }
        $this->object->suppressed_at = $at;
        $this->object->sync();
    }

    /**
     * Publication records only the current severity, never a hidden candidate's earlier peak.
     *
     * @param int $at Publication moment in UTC epoch milliseconds
     * @throws StatusIncidentWriteException When the incident is ended, suppressed, or the time precedes detection
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function publish(int $at): void
    {
        $this->ensureCanWrite();
        $this->assertOpen();
        $this->assertMoment($at);
        if ($this->object->suppressed_at !== null) {
            throw new StatusIncidentWriteException('Publication of this incident was canceled.');
        }
        if ($this->object->published_at !== null) {
            return;
        }
        $this->object->published_at = $at;
        $this->object->peak_severity = $this->object->severity;
        $this->object->sync();
    }

    /**
     * @param StatusSeverity $severity Latest proven abnormal severity
     * @throws StatusIncidentWriteException When the incident has ended
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function changeSeverity(StatusSeverity $severity): void
    {
        $this->ensureCanWrite();
        $this->assertOpen();
        $this->object->severity = $severity->value;
        if ($this->object->published_at !== null) {
            $this->object->peak_severity = StatusSeverity::from($this->object->peak_severity)->maximum($severity)->value;
        }
        $this->object->sync();
    }

    /**
     * Ends the fact and releases its unique active key in one persisted write.
     *
     * @param int $at Recovery moment in UTC epoch milliseconds
     * @throws StatusIncidentWriteException When the time precedes detection
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function resolve(int $at): void
    {
        $this->ensureCanWrite();
        $this->assertMoment($at);
        if ($this->object->resolved_at !== null) {
            return;
        }
        $this->object->outcome = match (true) {
            $this->object->published_at !== null => StatusIncidentOutcome::RESOLVED->value,
            $this->object->suppressed_at !== null => StatusIncidentOutcome::CANCELED_PUBLICATION->value,
            default => StatusIncidentOutcome::RECOVERED_BEFORE_PUBLICATION->value,
        };
        $this->object->resolved_at = $at;
        $this->object->active_component_key = null;
        $this->object->sync();
    }

    /** @throws StatusIncidentWriteException When a requested edit targets an ended incident */
    private function assertOpen(): void
    {
        if ($this->object->resolved_at !== null) {
            throw new StatusIncidentWriteException('This incident has already ended.');
        }
    }

    /**
     * @param int $at Moment in UTC epoch milliseconds
     * @throws StatusIncidentWriteException When the moment precedes detection
     */
    private function assertMoment(int $at): void
    {
        if ($at < $this->object->detected_at) {
            throw new StatusIncidentWriteException('The operation time cannot precede detection.');
        }
    }
}
