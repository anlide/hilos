<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Collection\SecondFactorResets as EntitySecondFactorResets;
use Hilos\Database\Entity\Item\SecondFactorReset as EntitySecondFactorReset;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorReset as ObjectSecondFactorReset;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\SqlSortDirection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorResets object collection - the delayed removals of second factors (HIL-494).
 *
 * {@see request()} opens one, {@see liveOf()} and {@see findLiveByToken()} answer the
 * profile, the sign-in step and the cancel link, and the two sweeps -
 * {@see dueBy()} for the removals whose time has come and {@see reminderDueBy()} for the
 * daily announcement - are what the users library's tick reads.
 *
 * @extends Objects<ObjectSecondFactorReset>
 * @method ObjectSecondFactorReset|null current()
 * @method ObjectSecondFactorReset|null first()
 * @method ObjectSecondFactorReset|null last()
 * @method ObjectSecondFactorReset|null get(int|string $key)
 * @method ObjectSecondFactorReset|null offsetGet(mixed $offset)
 */
class SecondFactorResets extends Objects
{
    public const string OBJECT_CLASS = ObjectSecondFactorReset::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySecondFactorResets::class;
    public const string COLLECTION_KEY = HilosDbContext::secondFactorResets;

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
            'SELECT `' . EntitySecondFactorReset::user_id . '` FROM `' . EntitySecondFactorReset::_table
                . '` WHERE `' . EntitySecondFactorReset::user_id . '` IN (?, ?) ORDER BY `'
                . EntitySecondFactorReset::user_id . '` FOR UPDATE NOWAIT',
            [$survivorId, $loserId],
        );
    }

    /** SQL condition naming a request that still stands. */
    private const string LIVE_CONDITION = '`' . EntitySecondFactorReset::canceled_at . '` IS NULL AND `'
        . EntitySecondFactorReset::completed_at . '` IS NULL';

    /**
     * Opens a delayed removal of a person's second factor.
     *
     * The announcement that goes out at once counts as the first one, so `notified_at` starts
     * at the request.
     *
     * The row is inserted without the token, and the token is written straight after, so it
     * stays out of the ORM columns. The caller commits the two together.
     *
     * @param int $userId Person whose second factor is to be removed
     * @param string $effectiveAt Moment the removal is carried out (SQL datetime)
     * @param string $cancelToken Token the cancel link carries
     * @return ObjectSecondFactorReset The request
     * @throws DatabaseException When the insert or the token write fails
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function request(int $userId, string $effectiveAt, string $cancelToken): ObjectSecondFactorReset
    {
        $now = TimeHelper::getSqlDateTime();
        $reset = static::OBJECT_CLASS::create();
        $reset->userId = $userId;
        $reset->requestedAt = $now;
        $reset->effectiveAt = $effectiveAt;
        $reset->notifiedAt = $now;
        $reset->sync();

        $id = $reset->id;
        if ($id === null) {
            throw new DatabaseException('Second factor reset insert did not assign an id');
        }
        $reset->writeCancelToken($cancelToken);
        $this[$id] = $reset;

        return $reset;
    }

    /**
     * The request of a person that still stands, if any.
     *
     * @param int $userId Person
     * @return ?ObjectSecondFactorReset The standing request, or null when there is none
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function liveOf(int $userId): ?ObjectSecondFactorReset
    {
        return $this->hydrateAll(static::entityClass()::get(
            '`' . EntitySecondFactorReset::user_id . '` = ? AND ' . self::LIVE_CONDITION,
            [$userId],
            [EntitySecondFactorReset::id => SqlSortDirection::DESC],
            1,
        ))[0] ?? null;
    }

    /**
     * The standing request a cancel link names, if any.
     *
     * The token is DB-only, so the lookup reads the id and then loads the row.
     *
     * @param string $cancelToken Token the link carries
     * @return ?ObjectSecondFactorReset The standing request, or null when the link names none
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws LogicException When the entity collection class is not configured
     */
    public function findLiveByToken(string $cancelToken): ?ObjectSecondFactorReset
    {
        if ($cancelToken === '') {
            return null;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($cancelToken));
        $row = Database::sql(
            'SELECT `' . EntitySecondFactorReset::id . '` FROM `' . EntitySecondFactorReset::_table
                . '` WHERE `' . EntitySecondFactorReset::cancel_token . '` = ? AND ' . self::LIVE_CONDITION
                . ' LIMIT 1',
            $params,
        )->firstRow();
        if ($row === null) {
            return null;
        }

        return $this->offsetGet((int)$row[EntitySecondFactorReset::id]);
    }

    /**
     * The standing requests whose removal time has come.
     *
     * @param string $now Current moment (SQL datetime)
     * @return list<ObjectSecondFactorReset> Requests due, oldest first
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function dueBy(string $now): array
    {
        return $this->hydrateAll(static::entityClass()::get(
            '`' . EntitySecondFactorReset::effective_at . '` <= ? AND ' . self::LIVE_CONDITION,
            [$now],
            [EntitySecondFactorReset::effective_at => SqlSortDirection::ASC],
        ));
    }

    /**
     * The standing requests not announced since a moment, and not due yet.
     *
     * @param string $now Current moment (SQL datetime)
     * @param string $notifiedBefore Announcements at or before this moment are stale (SQL datetime)
     * @return list<ObjectSecondFactorReset> Requests owing a reminder
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function reminderDueBy(string $now, string $notifiedBefore): array
    {
        return $this->hydrateAll(static::entityClass()::get(
            '`' . EntitySecondFactorReset::effective_at . '` > ? AND `' . EntitySecondFactorReset::notified_at
                . '` <= ? AND ' . self::LIVE_CONDITION,
            [$now, $notifiedBefore],
            [EntitySecondFactorReset::id => SqlSortDirection::ASC],
        ));
    }

    /**
     * Deletes every delayed removal of a person, ended ones too - the account is being erased (HIL-302).
     *
     * Each row leaves through its object so a delete announcement reaches every reader.
     * A person with none is not an error.
     *
     * @param int $userId Person whose rows to delete
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        foreach (static::entityClass()::get([EntitySecondFactorReset::user_id => $userId]) as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $this->objects[$id]->delete();
            unset($this[$id]);
        }
    }

    /**
     * Puts every row of an entity answer into this collection and answers the objects.
     *
     * @param EntityCollection<EntitySecondFactorReset> $entities Rows the database answered with
     * @return list<ObjectSecondFactorReset> Objects in answer order
     */
    private function hydrateAll(EntityCollection $entities): array
    {
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
}
