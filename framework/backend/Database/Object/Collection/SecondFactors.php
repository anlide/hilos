<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\SecondFactors as EntitySecondFactors;
use Hilos\Database\Entity\Item\SecondFactor as EntitySecondFactor;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactor as ObjectSecondFactor;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactors object collection - the authenticator apps of every person (HIL-494).
 *
 * Persistence primitives of the enrolment: {@see startEnrolment()} opens one - a person
 * has at most one unfinished enrolment, so a new one replaces it - and
 * {@see deleteForUser()} takes the whole second factor of a person out, which is what
 * switching it off and a completed reset both do. Reads by person go through
 * {@see listByUser()}.
 *
 * @extends Objects<ObjectSecondFactor>
 * @method ObjectSecondFactor|null current()
 * @method ObjectSecondFactor|null first()
 * @method ObjectSecondFactor|null last()
 * @method ObjectSecondFactor|null get(int|string $key)
 * @method ObjectSecondFactor|null offsetGet(mixed $offset)
 */
class SecondFactors extends Objects
{
    public const string OBJECT_CLASS = ObjectSecondFactor::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySecondFactors::class;
    public const string COLLECTION_KEY = HilosDbContext::secondFactors;

    /**
     * Locks both account sets before merge reads them, without waiting for another writer.
     * The caller must hold a transaction; this read neither opens nor commits one.
     *
     * @param int $survivorId Surviving account
     * @param int $loserId Folded account
     * @throws DatabaseException When a set is busy or its rows cannot be locked
     */
    public function lockForMerge(int $survivorId, int $loserId): void
    {
        Database::sql(
            'SELECT `' . EntitySecondFactor::user_id . '` FROM `' . EntitySecondFactor::_table
                . '` WHERE `' . EntitySecondFactor::user_id . '` IN (?, ?) ORDER BY `'
                . EntitySecondFactor::user_id . '` FOR UPDATE NOWAIT',
            [$survivorId, $loserId],
        );
    }

    /**
     * Opens an enrolment: an unconfirmed authenticator holding a fresh secret.
     *
     * The person's earlier unfinished enrolment, if any, is dropped first: asking for a new
     * secret replaces the one shown before, and an abandoned QR code must not stay able to
     * finish an enrolment later. Confirmed authenticators are untouched.
     *
     * @param int $userId Person enrolling
     * @param string $label Name the authenticator starts with
     * @param string $secret Base32 shared secret
     * @return ObjectSecondFactor The unconfirmed authenticator
     * @throws DatabaseException When a lookup, the insert or the secret write fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function startEnrolment(int $userId, string $label, string $secret): ObjectSecondFactor
    {
        foreach ($this->listByUser($userId) as $factor) {
            if ($factor->confirmedAt === null) {
                $this->remove($factor);
            }
        }

        $factor = static::OBJECT_CLASS::create();
        $factor->userId = $userId;
        $factor->label = $label;
        $factor->createdAt = TimeHelper::getSqlDateTime();
        $factor->sync();

        $id = $factor->id;
        if ($id === null) {
            throw new DatabaseException('Second factor insert did not assign an id');
        }
        $factor->writeSecret($secret);
        $this[$id] = $factor;

        return $factor;
    }

    /**
     * Lists every authenticator of a person, unfinished enrolments included.
     *
     * @param int $userId Person whose authenticators to list
     * @return list<ObjectSecondFactor> Authenticators in enrolment order (empty when none)
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function listByUser(int $userId): array
    {
        $entities = static::entityClass()::get(
            [EntitySecondFactor::user_id => $userId],
            orderBy: [EntitySecondFactor::id => SqlSortDirection::ASC],
        );

        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }

    /**
     * Moves confirmed authenticators inside the account-merge transaction.
     *
     * @param int $fromUserId Folded account
     * @param int $toUserId Surviving account
     * @throws WriteNotAllowedException When the caller cannot update both sets
     * @throws CreateNotAllowedException Never for persisted rows; declared by sync
     * @throws DatabaseException When reading or moving a row fails
     * @throws InvalidArgumentException When a query or sync frame is invalid
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a moved row has no key
     */
    public function rePointConfirmedToUser(int $fromUserId, int $toUserId): void
    {
        foreach ($this->listByUser($fromUserId) as $row) {
            if ($row->confirmedAt === null) {
                continue;
            }
            $row->userId = $toUserId;
            $row->sync();
        }
    }

    /**
     * Deletes every authenticator of a person - the second factor switched off whole.
     *
     * Each row leaves through its object so a delete announcement reaches every reader.
     * A person with none is not an error.
     *
     * @param int $userId Person whose authenticators to delete
     * @throws DatabaseException When a lookup or a delete fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        foreach ($this->listByUser($userId) as $factor) {
            $this->remove($factor);
        }
    }

    /**
     * Deletes one authenticator and forgets it here.
     *
     * @param ObjectSecondFactor $factor Authenticator to delete
     * @throws DatabaseException When the delete fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function remove(ObjectSecondFactor $factor): void
    {
        $id = $factor->id;
        $factor->delete();
        if ($id !== null) {
            unset($this[$id]);
        }
    }
}
