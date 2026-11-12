<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\StatusSilencePeriods as EntityStatusSilencePeriods;
use Hilos\Database\Entity\Item\StatusSilencePeriod as EntityStatusSilencePeriod;
use Hilos\Database\Object\Item\StatusSilencePeriod as ObjectStatusSilencePeriod;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\StatusSilencePeriods as ViewStatusSilencePeriods;
use Hilos\HilosException;

/**
 * Keyset reads over the immutable closed publication archive.
 *
 * @extends Objects<ObjectStatusSilencePeriod>
 * @method ObjectStatusSilencePeriod|null first()
 * @method ObjectStatusSilencePeriod|null last()
 * @method ObjectStatusSilencePeriod|null get(int|string $key)
 * @method ObjectStatusSilencePeriod|null offsetGet(mixed $offset)
 */
class StatusSilencePeriods extends Objects
{
    public const string OBJECT_CLASS = ObjectStatusSilencePeriod::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStatusSilencePeriods::class;
    public const string COLLECTION_KEY = HilosDbContext::statusSilencePeriods;

    /**
     * @param string $publicId Immutable gap identity at the import boundary
     * @return ?ObjectStatusSilencePeriod Persisted gap, or null when absent
     * @throws HilosException When the lookup cannot be executed
     */
    public function findByPublicId(string $publicId): ?ObjectStatusSilencePeriod
    {
        foreach ($this->loadByColumn(EntityStatusSilencePeriod::public_id, $publicId) as $id) {
            return $this->objects[$id];
        }
        return null;
    }

    /**
     * @param int $sinceMs Exclusive retention boundary in signed UTC epoch milliseconds
     * @param ?int $afterId Last processed primary id, null for the first portion
     * @param int $limit Requested rows, capped at 32; nonpositive reads are empty
     * @return static Detached bounded selection in ascending primary id order
     * @throws HilosException When the query or detached selection cannot be initialized
     */
    public function intersecting(int $sinceMs, ?int $afterId, int $limit): static
    {
        $selection = static::initEmpty();
        $limit = max(0, min(ViewStatusSilencePeriods::MAX_PORTION_ROWS, $limit));
        if ($limit === 0) {
            return $selection;
        }
        $rows = static::entityClass()::get( // fresh-sql: the lazy mirror cannot answer a retention predicate and bounded id keyset.
            filters: '`ended_at` > ? AND `id` > ?',
            filtersParam: [$sinceMs, $afterId ?? 0],
            orderBy: ['id' => SqlSortDirection::ASC],
            limit: $limit,
        );
        foreach ($rows as $id => $entity) {
            $selection->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
        }
        return $selection;
    }
}
