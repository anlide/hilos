<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorResets as ObjectSecondFactorResets;
use Hilos\Database\Object\Item\SecondFactorReset as ObjectSecondFactorReset;
use Hilos\Database\View\Item\SecondFactorReset;

/**
 * SecondFactorResets Db collection - the delayed removals of second factors (HIL-494).
 *
 * Read-facing representation of the framework-owned hilos_second_factor_reset table.
 *
 * @extends DbCollection<SecondFactorReset, ObjectSecondFactorResets>
 */
class SecondFactorResets extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactorReset::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorResets::class;

    /**
     * Reads the merge's two sets under a nonwaiting row lock and drops stale wrappers.
     * Called only inside the merge transaction, before any ordinary reads.
     *
     * @param int $survivorId Surviving account
     * @param int $loserId Folded account
     * @throws DatabaseException When a set is busy or its rows cannot be locked
     * @throws LogicException When the collection class constants are not configured
     */
    public function lockForMerge(int $survivorId, int $loserId): void
    {
        $this->objectCollection->lockForMerge($survivorId, $loserId);
        $this->clearCache();
    }

    /**
     * The request of a person that still stands, if any.
     *
     * @param int $userId Person
     * @return ?SecondFactorReset The standing request, or null when there is none
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function liveOf(int $userId): ?SecondFactorReset
    {
        return $this->itemFor($this->objectCollection->liveOf($userId));
    }

    /**
     * The standing request a cancel link names, if any.
     *
     * @param string $cancelToken Token the link carries
     * @return ?SecondFactorReset The standing request, or null when the link names none
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function findLiveByToken(string $cancelToken): ?SecondFactorReset
    {
        return $this->itemFor($this->objectCollection->findLiveByToken($cancelToken));
    }

    /**
     * The standing requests whose removal time has come.
     *
     * @param string $now Current moment (SQL datetime)
     * @return list<SecondFactorReset> Requests due, oldest first
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function dueBy(string $now): array
    {
        return $this->itemsFor($this->objectCollection->dueBy($now));
    }

    /**
     * The standing requests not announced since a moment, and not due yet.
     *
     * @param string $now Current moment (SQL datetime)
     * @param string $notifiedBefore Announcements at or before this moment are stale (SQL datetime)
     * @return list<SecondFactorReset> Requests owing a reminder
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function reminderDueBy(string $now, string $notifiedBefore): array
    {
        return $this->itemsFor($this->objectCollection->reminderDueBy($now, $notifiedBefore));
    }

    /**
     * Wraps one loaded request, if any.
     *
     * @param ?ObjectSecondFactorReset $object Loaded request, or null
     * @return ?SecondFactorReset Its item, or null
     * @throws InvalidArgumentException When the object type does not match the collection
     * @throws LogicException When collection class constants are not configured
     */
    private function itemFor(?ObjectSecondFactorReset $object): ?SecondFactorReset
    {
        $id = $object?->id;
        if ($object === null || $id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($id, $object);
    }

    /**
     * Wraps loaded requests.
     *
     * @param list<ObjectSecondFactorReset> $objects Loaded requests
     * @return list<SecondFactorReset> Their items, in the same order
     * @throws InvalidArgumentException When an object type does not match the collection
     * @throws LogicException When collection class constants are not configured
     */
    private function itemsFor(array $objects): array
    {
        $result = [];
        foreach ($objects as $object) {
            $item = $this->itemFor($object);
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
