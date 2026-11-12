<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\StatusSilencePeriodsActions;
use Hilos\Database\Object\Collection\StatusSilencePeriods as ObjectStatusSilencePeriods;
use Hilos\Database\View\Item\StatusSilencePeriod;
use Hilos\HilosException;

/**
 * Array access uses the internal persisted primary id.
 *
 * @extends DbCollection<StatusSilencePeriod, ObjectStatusSilencePeriods>
 * @method ObjectStatusSilencePeriods|null getObjectCollection()
 * @method StatusSilencePeriod|null first()
 * @method StatusSilencePeriod|null last()
 * @method StatusSilencePeriod offsetGet(mixed $offset)
 * @property-read StatusSilencePeriodsActions $actions Append-only archive imports
 */
class StatusSilencePeriods extends DbCollection
{
    public const int MAX_PORTION_ROWS = 32;
    public const string DB_ITEM_CLASS = StatusSilencePeriod::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusSilencePeriods::class;

    /**
     * @param string $publicId Immutable public identity
     * @return ?StatusSilencePeriod Matching gap, or null when absent
     * @throws HilosException When storage cannot be read
     */
    public function findByPublicId(string $publicId): ?StatusSilencePeriod
    {
        $object = $this->objectCollection->findByPublicId($publicId);
        return $object === null ? null : $this->createDbItem($object);
    }

    /**
     * @param int $sinceMs Exclusive retention boundary in signed UTC epoch milliseconds
     * @param ?int $afterId Last processed primary id, null for the first portion
     * @param int $limit Requested rows, capped at 32; nonpositive reads are empty
     * @return static Detached bounded selection in ascending primary id order
     * @throws HilosException When storage or selection initialization fails
     */
    public function intersecting(int $sinceMs, ?int $afterId, int $limit): static
    {
        $selection = static::init();
        $selection->setObjectCollection($this->objectCollection->intersecting($sinceMs, $afterId, $limit));
        return $selection;
    }
}
