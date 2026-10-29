<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\StatusWork as ObjectStatusWork;
use Hilos\Database\Object\Item\StatusWorkComponent as ObjectStatusWorkComponent;
use Hilos\Database\View\Item\StatusWork;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Status\StatusMaintenanceObservation;

/**
 * Joins the caller's transaction; no external side effects occur here.
 *
 * @extends DbActions<StatusWork, ObjectStatusWork>
 * @property-read ObjectStatusWork $object
 */
class StatusWorkActions extends DbActions
{
    /**
     * @param string $title Public title
     * @param ?string $description Optional public description
     * @param int $startsAt Inclusive UTC epoch millisecond start
     * @param int $endsAt Exclusive UTC epoch millisecond end
     * @param int $at Change moment in UTC epoch milliseconds
     * @param list<string> $componentKeys Complete selected catalog-key set
     * @throws HilosException When validation, ownership or persistence refuses the change
     */
    public function change(string $title, ?string $description, int $startsAt, int $endsAt, int $at, array $componentKeys): void
    {
        $this->ensureCanWrite();
        $this->assertUnfinished($at);
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('Work and component writes require the caller transaction');
        }
        ObjectStatusWork::validateContent($title, $description, $startsAt, $endsAt, $at);
        $componentKeys = ObjectStatusWorkComponent::normalizeKeys($componentKeys);
        $currentKeys = [];
        foreach (Hilos::$db->statusWorks[$this->object->id]->components as $component) {
            $currentKeys[] = $component->componentKey;
        }
        sort($currentKeys, SORT_STRING);
        if ($this->object->title === $title && $this->object->description === $description
            && $this->object->starts_at === $startsAt && $this->object->ends_at === $endsAt && $currentKeys === $componentKeys) {
            return;
        }
        Hilos::$db->statusWorkComponents->actions->replaceForWork($this->object->id, ...$componentKeys);
        $this->object->title = $title;
        $this->object->description = $description;
        $this->object->starts_at = $startsAt;
        $this->object->ends_at = $endsAt;
        $this->object->updated_at = $at;
        $this->object->version++;
        $this->object->sync();
    }

    /**
     * @param int $at Cancellation moment in UTC epoch milliseconds
     * @throws HilosException When ownership, lifecycle or persistence refuses cancellation
     */
    public function cancel(int $at): void
    {
        $this->ensureCanWrite();
        if ($this->object->canceled_at !== null) {
            return;
        }
        $this->assertUnfinished($at);
        $this->object->canceled_at = $at;
        $this->object->updated_at = $at;
        $this->object->version++;
        $this->object->sync();
    }

    /**
     * @param int $at Matching maintenance start in UTC epoch milliseconds
     * @throws HilosException When ownership, lifecycle or persistence refuses consumption
     */
    public function consume(int $at): void
    {
        $this->ensureCanWrite();
        if ($this->object->consumed_at !== null) {
            return;
        }
        $this->assertUnfinished($at);
        if ($at < $this->object->starts_at || $at >= $this->object->ends_at) {
            throw new ValidationException('Maintenance must start within the announced window.');
        }
        $this->recordConsumption($at);
    }

    /**
     * Joins the import transaction using the observation's validated announcement window.
     * A restore may move the current schedule but cannot undo proven use of the same public identity.
     *
     * @param StatusMaintenanceObservation $observation Own sanitized observation with a proven announcement snapshot
     * @throws HilosException When ownership, transaction, identity, lifecycle or persistence refuses reconciliation
     */
    public function consumeObservation(StatusMaintenanceObservation $observation): void
    {
        $this->ensureCanWrite();
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('Observed work consumption requires the caller transaction');
        }
        if ($observation->work === null || $observation->period->workId !== $this->object->public_id
            || $observation->period->startedAt < $this->object->created_at) {
            throw new ValidationException('Maintenance must prove use of this announcement.');
        }
        if ($this->object->consumed_at !== null) {
            return;
        }
        $this->assertUnfinished(max($observation->period->startedAt, $this->object->updated_at));
        $this->recordConsumption($observation->period->startedAt);
    }

    /**
     * @param int $at Expiry detection in UTC epoch milliseconds
     * @throws HilosException When ownership, lifecycle or persistence refuses expiry
     */
    public function expire(int $at): void
    {
        $this->ensureCanWrite();
        if ($this->object->expired_at !== null) {
            return;
        }
        $this->assertUnfinished($at);
        if ($at < $this->object->ends_at) {
            throw new ValidationException('This work window has not ended.');
        }
        $this->object->expired_at = $at;
        $this->object->updated_at = $at;
        $this->object->version++;
        $this->object->sync();
    }

    /**
     * Records a proven handoff, independently of the domain version and updatedAt.
     *
     * @param int $at Queue handoff moment in UTC epoch milliseconds
     * @throws HilosException When ownership or persistence refuses the monotonic update
     */
    public function markMailQueued(int $at): void
    {
        $this->ensureCanWrite();
        if ($at < $this->object->created_at) {
            throw new ValidationException('The mail handoff cannot precede work creation.');
        }
        if ($this->object->last_mail_queued_at !== null && $at <= $this->object->last_mail_queued_at) {
            return;
        }
        $this->object->last_mail_queued_at = $at;
        $this->object->sync();
    }

    /**
     * @param int $at Proven maintenance start in UTC epoch milliseconds
     * @throws HilosException When the consumed state cannot be persisted
     */
    private function recordConsumption(int $at): void
    {
        $this->object->consumed_at = $at;
        $this->object->updated_at = max($at, $this->object->updated_at);
        $this->object->version++;
        $this->object->sync();
    }

    /**
     * @param int $at Mutation moment in UTC epoch milliseconds
     * @throws ValidationException When the work has finished or the mutation moves backward
     */
    private function assertUnfinished(int $at): void
    {
        if ($this->object->canceled_at !== null || $this->object->consumed_at !== null || $this->object->expired_at !== null) {
            throw new ValidationException('This work announcement has already finished.');
        }
        if ($at < $this->object->updated_at) {
            throw new ValidationException('The change cannot precede the last work update.');
        }
    }
}
