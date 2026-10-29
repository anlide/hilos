<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\StatusWorksActions;
use Hilos\Database\Object\Collection\StatusWorks as ObjectStatusWorks;
use Hilos\Database\View\Item\StatusWork;
use Hilos\HilosException;
use Hilos\Status\StatusWorksSummaryData;

/**
 * Array access uses the persisted primary id.
 *
 * @extends DbCollection<StatusWork, ObjectStatusWorks>
 * @method ObjectStatusWorks|null getObjectCollection()
 * @method StatusWork|null first()
 * @method StatusWork|null last()
 * @method StatusWork offsetGet(mixed $offset)
 * @property-read StatusWorksActions $actions Storage writes
 */
class StatusWorks extends DbCollection
{
    public const int MAX_PORTION_ROWS = 32;
    public const string DB_ITEM_CLASS = StatusWork::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusWorks::class;

    /**
     * @param string $publicId Immutable public identity
     * @return ?StatusWork Matching row, or null when absent
     * @throws HilosException When storage cannot be read
     */
    public function findByPublicId(string $publicId): ?StatusWork
    {
        $object = $this->objectCollection->findByPublicId($publicId);
        return $object === null ? null : $this->createDbItem($object);
    }

    /**
     * @param int $nowMs UTC epoch millisecond boundary
     * @param ?int $afterId Last processed primary id, null for the first portion
     * @param int $limit Requested rows, capped at 32; nonpositive reads are empty
     * @return static Detached bounded selection in ascending id order
     * @throws HilosException When storage or selection initialization fails
     */
    public function upcomingPortion(int $nowMs, ?int $afterId, int $limit): static
    {
        $selection = static::init();
        $selection->setObjectCollection($this->objectCollection->upcomingPortion($nowMs, $afterId, $limit));
        return $selection;
    }

    /**
     * @param int $nowMs Expiry detection in UTC epoch milliseconds
     * @return static At most 32 unfinished announcements whose windows have ended
     * @throws HilosException When storage or selection initialization fails
     */
    public function expiredPortion(int $nowMs): static
    {
        $selection = static::init();
        $selection->setObjectCollection($this->objectCollection->expiredPortion($nowMs));
        return $selection;
    }

    /**
     * @param int $startsAt Inclusive proposed start in UTC epoch milliseconds
     * @param int $endsAt Exclusive proposed end in UTC epoch milliseconds
     * @param ?int $excludeId Persisted work being changed, null for creation
     * @return ?StatusWork First noncanceled conflicting work
     * @throws HilosException When storage cannot be read
     */
    public function conflictingWindow(int $startsAt, int $endsAt, ?int $excludeId = null): ?StatusWork
    {
        $object = $this->objectCollection->conflictingWindow($startsAt, $endsAt, $excludeId);
        return $object === null ? null : $this->createDbItem($object);
    }

    /**
     * @param int $startedAt Observed maintenance start in UTC epoch milliseconds
     * @return ?StatusWork Unfinished announcement covering [start, end)
     * @throws HilosException When storage cannot be read
     */
    public function matchingAnnouncement(int $startedAt): ?StatusWork
    {
        $object = $this->objectCollection->matchingAnnouncement($startedAt);
        return $object === null ? null : $this->createDbItem($object);
    }

    /**
     * @param int $nowMs Current UTC epoch milliseconds
     * @return StatusWorksSummaryData Unexpired unfinished count and nearest start
     * @throws HilosException When storage cannot be read
     */
    public function summary(int $nowMs): StatusWorksSummaryData
    {
        return $this->objectCollection->summary($nowMs);
    }
}
