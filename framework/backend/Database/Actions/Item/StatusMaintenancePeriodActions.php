<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Item\StatusMaintenancePeriod as ObjectStatusMaintenancePeriod;
use Hilos\Database\View\Item\StatusMaintenancePeriod;
use Hilos\HilosException;

/**
 * Joins the caller's transaction; no external side effects occur here.
 *
 * @extends DbActions<StatusMaintenancePeriod, ObjectStatusMaintenancePeriod>
 * @property-read ObjectStatusMaintenancePeriod $object
 */
class StatusMaintenancePeriodActions extends DbActions
{
    /**
     * Replaying an older observation cannot reopen a completed period or move its occupied fact backward.
     * An inexact completion never gains an exact end from a later replay.
     *
     * @param int $lastObservedAt Latest occupied fact in UTC epoch milliseconds
     * @param ?int $endedAt Exact end from continuous observation, otherwise null
     * @param ?int $completionObservedAt Proven completion detection, null while unfinished
     * @throws HilosException When validation, ownership or persistence refuses reconciliation
     */
    public function reconcile(int $lastObservedAt, ?int $endedAt, ?int $completionObservedAt): void
    {
        $this->ensureCanWrite();
        ObjectStatusMaintenancePeriod::validateObservation($this->object->started_at, $lastObservedAt, $endedAt, $completionObservedAt);
        if ($this->object->completion_observed_at !== null) {
            if ($completionObservedAt !== null && ($completionObservedAt !== $this->object->completion_observed_at
                || $endedAt !== $this->object->ended_at || $lastObservedAt > $this->object->last_observed_at)) {
                throw new ValidationException('A completed maintenance observation cannot be rewritten.');
            }
            return;
        }
        if ($lastObservedAt < $this->object->last_observed_at) {
            return;
        }
        if ($lastObservedAt === $this->object->last_observed_at && $completionObservedAt === null) {
            return;
        }
        $this->object->last_observed_at = $lastObservedAt;
        $this->object->ended_at = $endedAt;
        $this->object->completion_observed_at = $completionObservedAt;
        $this->object->sync();
    }
}
