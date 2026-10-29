<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\StatusMaintenancePeriods as EntityStatusMaintenancePeriods;
use Hilos\Database\Entity\Item\StatusMaintenancePeriod as EntityStatusMaintenancePeriod;
use Hilos\Database\Object\Item\StatusMaintenancePeriod as ObjectStatusMaintenancePeriod;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\StatusMaintenancePeriods as ViewStatusMaintenancePeriods;
use Hilos\HilosException;

/**
 * @extends Objects<ObjectStatusMaintenancePeriod>
 * @method ObjectStatusMaintenancePeriod|null first()
 * @method ObjectStatusMaintenancePeriod|null last()
 * @method ObjectStatusMaintenancePeriod|null get(int|string $key)
 * @method ObjectStatusMaintenancePeriod|null offsetGet(mixed $offset)
 */
class StatusMaintenancePeriods extends Objects
{
    public const string OBJECT_CLASS = ObjectStatusMaintenancePeriod::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStatusMaintenancePeriods::class;
    public const string COLLECTION_KEY = HilosDbContext::statusMaintenancePeriods;

    /**
     * @param string $publicId Immutable observation identity at the import boundary
     * @return ?ObjectStatusMaintenancePeriod Persisted period, if already imported
     * @throws HilosException When the lookup cannot be executed
     */
    public function findByPublicId(string $publicId): ?ObjectStatusMaintenancePeriod
    {
        foreach ($this->loadByColumn(EntityStatusMaintenancePeriod::public_id, $publicId) as $id) {
            return $this->objects[$id];
        }
        return null;
    }

    /**
     * An unknown gap after lastObservedAt is never treated as proven maintenance.
     *
     * @param int $sinceMs Inclusive completion retention boundary in UTC epoch milliseconds
     * @param ?int $afterId Last processed primary id, null for the first portion
     * @param int $limit Requested rows, capped at 32; nonpositive reads are empty
     * @return static Detached active periods and completed periods retained at the boundary
     * @throws HilosException When the query or detached selection cannot be initialized
     */
    public function intersecting(int $sinceMs, ?int $afterId, int $limit): static
    {
        $selection = static::initEmpty();
        $limit = max(0, min(ViewStatusMaintenancePeriods::MAX_PORTION_ROWS, $limit));
        if ($limit === 0) {
            return $selection;
        }
        $rows = static::entityClass()::get( // fresh-sql: retained history needs a completion boundary and a bounded id keyset.
            filters: '(`completion_observed_at` IS NULL OR `ended_at` >= ?'
                . ' OR (`ended_at` IS NULL AND `completion_observed_at` >= ?)) AND `id` > ?',
            filtersParam: [$sinceMs, $sinceMs, $afterId ?? 0],
            orderBy: ['id' => SqlSortDirection::ASC],
            limit: $limit,
        );
        foreach ($rows as $id => $entity) {
            $selection->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
        }
        return $selection;
    }
}
