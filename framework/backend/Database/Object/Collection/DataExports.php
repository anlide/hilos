<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\DataExport\DataExportState;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\DataExports as EntityDataExports;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\DataExport as EntityDataExport;
use Hilos\Database\Object\Item\DataExport as ObjectDataExport;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;

/**
 * Durable requests, ordered by request time with the id breaking ties.
 *
 * @extends Objects<ObjectDataExport>
 */
class DataExports extends Objects
{
    public const string OBJECT_CLASS = ObjectDataExport::class;
    public const string ENTITY_COLLECTION_CLASS = EntityDataExports::class;
    public const string COLLECTION_KEY = HilosDbContext::dataExports;

    /**
     * @param int $userId Owner of the new request
     * @param string $requestedAt Request time in SQL UTC
     * @return ObjectDataExport Inserted request
     * @throws HilosException When the insert or announcement fails
     */
    public function order(int $userId, string $requestedAt): ObjectDataExport
    {
        $request = static::OBJECT_CLASS::create();
        $request->userId = $userId;
        $request->requestedAt = $requestedAt;
        $request->state = DataExportState::PREPARING;
        $request->sync();
        $id = $request->id;
        if ($id === null) {
            throw new DatabaseException('Data export insert did not assign an id');
        }
        $this[$id] = $request;

        return $request;
    }

    /**
     * @param int $userId Person whose copy is sought
     * @return ?ObjectDataExport The person's one request, if any
     * @throws HilosException When the query fails
     */
    public function ofUser(int $userId): ?ObjectDataExport
    {
        return $this->hydrateAll(static::entityClass()::get([EntityDataExport::user_id => $userId]))[0] ?? null;
    }

    /**
     * @return ?ObjectDataExport Earliest unfinished request, if any
     * @throws HilosException When the query fails
     */
    public function nextPreparing(): ?ObjectDataExport
    {
        return $this->hydrateAll(static::entityClass()::get(
            [EntityDataExport::state => DataExportState::PREPARING],
            [],
            [EntityDataExport::requested_at => SqlSortDirection::ASC, EntityDataExport::id => SqlSortDirection::ASC],
            1,
        ))[0] ?? null;
    }

    /**
     * @param string $now Expiry boundary in SQL UTC
     * @return list<ObjectDataExport> Rows for the expiry sweep
     * @throws HilosException When the query fails
     */
    public function expiredBy(string $now): array
    {
        return $this->hydrateAll(static::entityClass()::get('`expires_at` <= ?', [$now]));
    }

    /**
     * @return list<ObjectDataExport> Ready rows for startup file reconciliation
     * @throws HilosException When the query fails
     */
    public function allReady(): array
    {
        return $this->hydrateAll(static::entityClass()::get([EntityDataExport::state => DataExportState::READY]));
    }

    /**
     * @param EntityCollection<EntityDataExport> $entities Rows returned by the database
     * @return list<ObjectDataExport> Queue rows in query order
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
