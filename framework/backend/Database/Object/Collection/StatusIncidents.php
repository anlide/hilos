<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\StatusIncidents as EntityStatusIncidents;
use Hilos\Database\Object\Item\StatusIncident as ObjectStatusIncident;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\StatusIncidents as ViewStatusIncidents;
use Hilos\HilosException;

/**
 * @extends Objects<ObjectStatusIncident>
 * @method ObjectStatusIncident|null first()
 * @method ObjectStatusIncident|null last()
 * @method ObjectStatusIncident|null get(int|string $key)
 * @method ObjectStatusIncident|null offsetGet(mixed $offset)
 */
class StatusIncidents extends Objects
{
    public const string OBJECT_CLASS = ObjectStatusIncident::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStatusIncidents::class;
    public const string COLLECTION_KEY = HilosDbContext::statusIncidents;

    /**
     * Reads a finite detached selection; the lazy source store does not retain these rows.
     *
     * @param int $sinceMs Inclusive resolved boundary in epoch milliseconds UTC
     * @param ?int $afterId Last incident id, or null for the first portion
     * @param int $limit Requested rows, capped at the public-history portion size
     * @return static Published open or intersecting ended incidents in ascending id order
     * @throws HilosException When query execution or selection initialization fails
     */
    public function publishedIntersecting(int $sinceMs, ?int $afterId, int $limit): static
    {
        $selection = static::initEmpty();
        $limit = max(0, min(ViewStatusIncidents::MAX_PORTION_ROWS, $limit));
        if ($limit === 0) {
            return $selection;
        }
        $rows = static::entityClass()::get( // fresh-sql: the shared column loader cannot bound publication, overlap and keyset together.
            filters: '`published_at` IS NOT NULL AND (`resolved_at` IS NULL OR `resolved_at` >= ?) AND `id` > ?',
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
