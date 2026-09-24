<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Collection\SecondFactorResets as EntitySecondFactorResets;
use Hilos\Database\Entity\Item\SecondFactorReset as EntitySecondFactorReset;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorReset as ObjectSecondFactorReset;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorResets object collection - the delayed removals of second factors (HIL-494).
 *
 * {@see request()} opens one, {@see liveOf()} and {@see findLiveByTokenHash()} answer the
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
final class SecondFactorResets extends Objects
{
    public const string OBJECT_CLASS = ObjectSecondFactorReset::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySecondFactorResets::class;
    public const string COLLECTION_KEY = HilosDbContext::secondFactorResets;

    /** SQL condition naming a request that still stands. */
    private const string LIVE_CONDITION = '`' . EntitySecondFactorReset::canceled_at . '` IS NULL AND `'
        . EntitySecondFactorReset::completed_at . '` IS NULL';

    /**
     * Opens a delayed removal of a person's second factor.
     *
     * The announcement that goes out at once counts as the first one, so `notified_at` starts
     * at the request.
     *
     * @param int $userId Person whose second factor is to be removed
     * @param string $effectiveAt Moment the removal is carried out (SQL datetime)
     * @param string $cancelTokenHash sha256 (hex) of the token the cancel link carries
     * @return ObjectSecondFactorReset The request
     * @throws DatabaseException When the insert fails
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function request(int $userId, string $effectiveAt, string $cancelTokenHash): ObjectSecondFactorReset
    {
        $now = TimeHelper::getSqlDateTime();
        $reset = ObjectSecondFactorReset::create();
        $reset->userId = $userId;
        $reset->requestedAt = $now;
        $reset->effectiveAt = $effectiveAt;
        $reset->cancelTokenHash = $cancelTokenHash;
        $reset->notifiedAt = $now;
        $reset->sync();

        $id = $reset->id;
        if ($id === null) {
            throw new DatabaseException('Second factor reset insert did not assign an id');
        }
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
        return $this->hydrateAll(EntitySecondFactorReset::get(
            '`' . EntitySecondFactorReset::user_id . '` = ? AND ' . self::LIVE_CONDITION,
            [$userId],
            [EntitySecondFactorReset::id => SqlSortDirection::DESC],
            1,
        ))[0] ?? null;
    }

    /**
     * The standing request a cancel link names, if any.
     *
     * @param string $cancelTokenHash sha256 (hex) of the token the link carries
     * @return ?ObjectSecondFactorReset The standing request, or null when the link names none
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findLiveByTokenHash(string $cancelTokenHash): ?ObjectSecondFactorReset
    {
        return $this->hydrateAll(EntitySecondFactorReset::get(
            '`' . EntitySecondFactorReset::cancel_token_hash . '` = ? AND ' . self::LIVE_CONDITION,
            [$cancelTokenHash],
            [],
            1,
        ))[0] ?? null;
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
        return $this->hydrateAll(EntitySecondFactorReset::get(
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
        return $this->hydrateAll(EntitySecondFactorReset::get(
            '`' . EntitySecondFactorReset::effective_at . '` > ? AND `' . EntitySecondFactorReset::notified_at
                . '` <= ? AND ' . self::LIVE_CONDITION,
            [$now, $notifiedBefore],
            [EntitySecondFactorReset::id => SqlSortDirection::ASC],
        ));
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
                $this->hydrate($entity->id, ObjectSecondFactorReset::fromEntity($entity));
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }
}
