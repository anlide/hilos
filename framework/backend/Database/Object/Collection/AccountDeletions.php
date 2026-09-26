<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\AccountDeletions as EntityAccountDeletions;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\AccountDeletion as EntityAccountDeletion;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * AccountDeletions object collection - people's own requests to delete their accounts (HIL-302).
 *
 * {@see request()} opens one, {@see liveOf()} answers the profile and the account commands,
 * and {@see dueBy()} is what the session holder's erasure sweep reads.
 *
 * @extends Objects<ObjectAccountDeletion>
 * @method ObjectAccountDeletion|null current()
 * @method ObjectAccountDeletion|null first()
 * @method ObjectAccountDeletion|null last()
 * @method ObjectAccountDeletion|null get(int|string $key)
 * @method ObjectAccountDeletion|null offsetGet(mixed $offset)
 */
final class AccountDeletions extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountDeletion::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountDeletions::class;
    public const string COLLECTION_KEY = HilosDbContext::accountDeletions;

    /** SQL condition naming a request that still stands. */
    private const string LIVE_CONDITION = '`' . EntityAccountDeletion::canceled_at . '` IS NULL AND `'
        . EntityAccountDeletion::completed_at . '` IS NULL';

    /**
     * Opens a request to delete a person's account.
     *
     * @param int $userId Person whose account is to be deleted
     * @param string $effectiveAt Moment the account is erased (SQL datetime)
     * @return ObjectAccountDeletion The request
     * @throws DatabaseException When the insert fails
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException Never for a new row; declared by the storing sync
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function request(int $userId, string $effectiveAt): ObjectAccountDeletion
    {
        $deletion = ObjectAccountDeletion::create();
        $deletion->userId = $userId;
        $deletion->requestedAt = TimeHelper::getSqlDateTime();
        $deletion->effectiveAt = $effectiveAt;
        $deletion->sync();

        $id = $deletion->id;
        if ($id === null) {
            throw new DatabaseException('Account deletion insert did not assign an id');
        }
        $this[$id] = $deletion;

        return $deletion;
    }

    /**
     * The request of a person that still stands, if any.
     *
     * @param int $userId Person
     * @return ?ObjectAccountDeletion The standing request, or null when there is none
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function liveOf(int $userId): ?ObjectAccountDeletion
    {
        return $this->hydrateAll(EntityAccountDeletion::get(
            '`' . EntityAccountDeletion::user_id . '` = ? AND ' . self::LIVE_CONDITION,
            [$userId],
            [EntityAccountDeletion::id => SqlSortDirection::DESC],
            1,
        ))[0] ?? null;
    }

    /**
     * The standing requests whose erasure time has come.
     *
     * @param string $now Current moment (SQL datetime)
     * @return list<ObjectAccountDeletion> Requests due, oldest first
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function dueBy(string $now): array
    {
        return $this->hydrateAll(EntityAccountDeletion::get(
            '`' . EntityAccountDeletion::effective_at . '` <= ? AND ' . self::LIVE_CONDITION,
            [$now],
            [EntityAccountDeletion::effective_at => SqlSortDirection::ASC],
        ));
    }

    /**
     * Puts every row of an entity answer into this collection and answers the objects.
     *
     * @param EntityCollection<EntityAccountDeletion> $entities Rows the database answered with
     * @return list<ObjectAccountDeletion> Objects in answer order
     */
    private function hydrateAll(EntityCollection $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, ObjectAccountDeletion::fromEntity($entity));
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }
}
