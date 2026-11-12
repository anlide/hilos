<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\SecondFactorTrustRestrictions as EntitySecondFactorTrustRestrictions;
use Hilos\Database\Entity\Item\SecondFactorTrustRestriction as EntitySecondFactorTrustRestriction;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorTrustRestriction as ObjectSecondFactorTrustRestriction;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;

/**
 * SecondFactorTrustRestrictions object collection - the shortenings of browser trust (HIL-1900).
 *
 * {@see record()} writes one when the trust term is shortened or the second factor is switched off
 * with the trusted browsers forgotten; rows are never changed afterwards. {@see savedSince()} and
 * {@see has()} read the table itself rather than this process's memory: the settings library
 * writes a restriction in its own process, and the holder that applies it has to see the row the
 * library has just written.
 *
 * @extends Objects<ObjectSecondFactorTrustRestriction>
 * @method ObjectSecondFactorTrustRestriction|null current()
 * @method ObjectSecondFactorTrustRestriction|null first()
 * @method ObjectSecondFactorTrustRestriction|null last()
 * @method ObjectSecondFactorTrustRestriction|null get(int|string $key)
 * @method ObjectSecondFactorTrustRestriction|null offsetGet(mixed $offset)
 */
class SecondFactorTrustRestrictions extends Objects
{
    public const string OBJECT_CLASS = ObjectSecondFactorTrustRestriction::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySecondFactorTrustRestrictions::class;
    public const string COLLECTION_KEY = HilosDbContext::secondFactorTrustRestrictions;

    /**
     * Stores one restriction.
     *
     * @param int $savedAt Moment the setting was saved (epoch milliseconds)
     * @param int $termDays Days a trust granted by then may still live; 0 takes it out
     * @return int Primary id of the stored row
     * @throws DatabaseException When the insert fails or assigns no id
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function record(int $savedAt, int $termDays): int
    {
        $entity = static::entityClass()::getEmpty();
        $entity->saved_at = $savedAt;
        $entity->term_days = $termDays;
        $restriction = static::OBJECT_CLASS::fromEntity($entity);
        $restriction->sync();
        if ($restriction->id === null) {
            throw new DatabaseException('Second factor trust restriction insert did not assign an id');
        }
        $this[$restriction->id] = $restriction;

        return $restriction->id;
    }

    /**
     * Restrictions saved at or after a moment, oldest first.
     *
     * @param int $moment Earliest save moment to include (epoch milliseconds)
     * @return static Matching rows in ascending id order
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException When the collection refuses to start an empty selection
     */
    public function savedSince(int $moment): static
    {
        $entities = static::entityClass()::get( // fresh-sql: the settings library writes restrictions in another process.
            '`' . EntitySecondFactorTrustRestriction::saved_at . '` >= ?',
            [$moment],
            [EntitySecondFactorTrustRestriction::id => SqlSortDirection::ASC],
        );
        $selection = static::initEmpty();
        foreach ($entities as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $selection->hydrate($id, $this->objects[$id]);
        }

        return $selection;
    }

    /**
     * Whether a restriction is stored.
     *
     * @param int $id Restriction primary id
     * @return bool True when the table holds that row
     * @throws DatabaseException When the count fails
     */
    public function has(int $id): bool
    {
        return static::entityClass()::count( // fresh-sql: the settings library writes restrictions in another process.
            [EntitySecondFactorTrustRestriction::id => $id],
        ) > 0;
    }
}
