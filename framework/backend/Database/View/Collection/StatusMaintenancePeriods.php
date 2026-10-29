<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Database\Actions\Collection\StatusMaintenancePeriodsActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StatusMaintenancePeriods as ObjectStatusMaintenancePeriods;
use Hilos\Database\View\Item\StatusMaintenancePeriod;
use Hilos\HilosException;
use Hilos\Status\DTO\StatusHistoryPortion;
use Hilos\Status\DTO\StatusHistoryQuery;

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

    /**
     * @param StatusHistoryQuery $query Validated filters and bounded source window
     * @return StatusHistoryPortion Detached snapshots in canonical order, excluding the edge probe
     * @throws DatabaseException When storage cannot be read or a completed row is invalid
     * @throws ValidationException When the bounded snapshot cannot be formed
     */
    public function historyPortion(StatusHistoryQuery $query): StatusHistoryPortion
    {
        return $this->objectCollection->historyPortion($query);
    }

    /**
     * @param StatusHistoryQuery $query Same predicate and optional tuple side as the portion reader
     * @return TableFacetCountDTO Exact or capped source count, independent of edgeSkip and limit
     * @throws DatabaseException When storage cannot be read
     */
    public function historyCount(StatusHistoryQuery $query): TableFacetCountDTO
    {
        return $this->objectCollection->historyCount($query);
    }

    /**
     * @param int $id Known source primary key
     * @param StatusHistoryQuery $query Same completed/filter/search predicate as the window
     * @return bool Whether this source row belongs to the queried set
     * @throws DatabaseException When storage cannot be read
     */
    public function historyContains(int $id, StatusHistoryQuery $query): bool
    {
        return $this->objectCollection->historyContains($id, $query);
    }

    /**
     * @return int All completed source facts without UI filters
     * @throws DatabaseException When storage cannot be read
     */
    public function completedCount(): int
    {
        return $this->objectCollection->completedCount();
    }

    /**
     * @return list<string> Distinct saved component keys from completed facts
     * @throws DatabaseException When storage cannot be read
     */
    public function historyComponentKeys(): array
    {
        return $this->objectCollection->historyComponentKeys();
    }
}
