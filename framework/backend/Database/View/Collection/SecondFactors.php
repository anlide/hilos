<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactors as ObjectSecondFactors;
use Hilos\Database\View\Item\SecondFactor;

/**
 * SecondFactors Db collection - the authenticator apps of every person (HIL-494).
 *
 * Read-facing representation of the framework-owned hilos_second_factor table. A person's
 * authenticators are read through {@see listByUser()}, {@see confirmedOf()} and
 * {@see unconfirmedOf()}; writes go through the collection and item actions.
 *
 * @extends DbCollection<SecondFactor, ObjectSecondFactors>
 */
class SecondFactors extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactor::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactors::class;

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
     * Lists every authenticator of a person, unfinished enrolments included.
     *
     * @param int $userId Person
     * @return list<SecondFactor> Authenticators in enrolment order (empty when none)
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function listByUser(int $userId): array
    {
        $result = [];
        foreach ($this->objectCollection->listByUser($userId) as $object) {
            $id = $object->id;
            if ($id !== null) {
                $result[] = $this->getOrCreateItemForLoadedObject($id, $object);
            }
        }

        return $result;
    }

    /**
     * Lists the confirmed authenticators of a person - their second factor.
     *
     * @param int $userId Person
     * @return list<SecondFactor> Confirmed authenticators in enrolment order (empty when the person has no second factor)
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function confirmedOf(int $userId): array
    {
        return array_values(array_filter(
            $this->listByUser($userId),
            static fn (SecondFactor $factor): bool => $factor->confirmedAt !== null,
        ));
    }

    /**
     * The enrolment of a person that was started and not confirmed, if any.
     *
     * @param int $userId Person
     * @return ?SecondFactor The unfinished enrolment, or null when there is none
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function unconfirmedOf(int $userId): ?SecondFactor
    {
        foreach ($this->listByUser($userId) as $factor) {
            if ($factor->confirmedAt === null) {
                return $factor;
            }
        }

        return null;
    }
}
