<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\StatusMaintenancePeriodsActions;
use Hilos\Database\Object\Collection\StatusMaintenancePeriods as ObjectStatusMaintenancePeriods;
use Hilos\Database\View\Item\StatusMaintenancePeriod;
use Hilos\HilosException;

/**
 * Array access uses the persisted primary id.
 *
 * @extends DbCollection<StatusMaintenancePeriod, ObjectStatusMaintenancePeriods>
 * @method ObjectStatusMaintenancePeriods|null getObjectCollection()
 * @method StatusMaintenancePeriod|null first()
 * @method StatusMaintenancePeriod|null last()
 * @method StatusMaintenancePeriod offsetGet(mixed $offset)
 * @property-read StatusMaintenancePeriodsActions $actions Storage writes
 */
class StatusMaintenancePeriods extends DbCollection
{
    public const int MAX_PORTION_ROWS = 32;
    public const string DB_ITEM_CLASS = StatusMaintenancePeriod::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusMaintenancePeriods::class;

    /**
     * @param string $publicId Immutable public identity
     * @return ?StatusMaintenancePeriod Matching row, or null when absent
     * @throws HilosException When storage cannot be read
     */
    public function findByPublicId(string $publicId): ?StatusMaintenancePeriod
    {
        $object = $this->objectCollection->findByPublicId($publicId);
        return $object === null ? null : $this->createDbItem($object);
    }

    /**
     * @param int $sinceMs UTC epoch millisecond boundary
     * @param ?int $afterId Last processed primary id, null for the first portion
     * @param int $limit Requested rows, capped at 32; nonpositive reads are empty
     * @return static Detached bounded selection in ascending id order
     * @throws HilosException When storage or selection initialization fails
     */
    public function intersecting(int $sinceMs, ?int $afterId, int $limit): static
    {
        $selection = static::init();
        $selection->setObjectCollection($this->objectCollection->intersecting($sinceMs, $afterId, $limit));
        return $selection;
    }
}
