<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Collection\LegalAcceptanceExports as EntityLegalAcceptanceExports;
use Hilos\Database\Entity\Item\LegalAcceptanceExport as EntityLegalAcceptanceExport;
use Hilos\Database\Object\Item\LegalAcceptanceExport as ObjectLegalAcceptanceExport;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;
use Hilos\Legal\Export\LegalAcceptancesExportState;

/**
 * Durable orders of acceptance files, ordered by request time with the id breaking ties.
 *
 * @extends Objects<ObjectLegalAcceptanceExport>
 */
class LegalAcceptanceExports extends Objects
{
    public const string OBJECT_CLASS = ObjectLegalAcceptanceExport::class;
    public const string ENTITY_COLLECTION_CLASS = EntityLegalAcceptanceExports::class;
    public const string COLLECTION_KEY = HilosDbContext::legalAcceptanceExports;

    /**
     * @param int $userId Administrator placing the order
     * @param ?string $document Document filter of the order, or null for every document
     * @param ?string $revisionId Revision filter of the order, or null for every revision
     * @param ?string $search Search of the order, or null for none
     * @param string $requestedAt Request time in SQL UTC
     * @return ObjectLegalAcceptanceExport Inserted order
     * @throws HilosException When the insert or announcement fails
     */
    public function order(int $userId, ?string $document, ?string $revisionId, ?string $search, string $requestedAt): ObjectLegalAcceptanceExport
    {
        $order = static::OBJECT_CLASS::create();
        $order->userId = $userId;
        $order->document = $document;
        $order->revisionId = $revisionId;
        $order->search = $search;
        $order->requestedAt = $requestedAt;
        $order->state = LegalAcceptancesExportState::PREPARING;
        $order->sync();
        $id = $order->id;
        if ($id === null) {
            throw new DatabaseException('Legal acceptances export insert did not assign an id');
        }
        $this[$id] = $order;

        return $order;
    }

    /**
     * @param int $userId Administrator whose order is sought
     * @return ?ObjectLegalAcceptanceExport The administrator's one order, if any
     * @throws HilosException When the query fails
     */
    public function ofUser(int $userId): ?ObjectLegalAcceptanceExport
    {
        return $this->hydrateAll(static::entityClass()::get([EntityLegalAcceptanceExport::user_id => $userId]))[0] ?? null;
    }

    /**
     * @return ?ObjectLegalAcceptanceExport Earliest unfinished order, if any
     * @throws HilosException When the query fails
     */
    public function nextPreparing(): ?ObjectLegalAcceptanceExport
    {
        return $this->hydrateAll(static::entityClass()::get(
            [EntityLegalAcceptanceExport::state => LegalAcceptancesExportState::PREPARING],
            [],
            [EntityLegalAcceptanceExport::requested_at => SqlSortDirection::ASC, EntityLegalAcceptanceExport::id => SqlSortDirection::ASC],
            1,
        ))[0] ?? null;
    }

    /**
     * @param string $now Expiry boundary in SQL UTC
     * @return list<ObjectLegalAcceptanceExport> Rows for the expiry sweep
     * @throws HilosException When the query fails
     */
    public function expiredBy(string $now): array
    {
        return $this->hydrateAll(static::entityClass()::get('`expires_at` <= ?', [$now]));
    }

    /**
     * @return list<ObjectLegalAcceptanceExport> Ready rows for file reconciliation
     * @throws HilosException When the query fails
     */
    public function allReady(): array
    {
        return $this->hydrateAll(static::entityClass()::get([EntityLegalAcceptanceExport::state => LegalAcceptancesExportState::READY]));
    }

    /**
     * @return list<ObjectLegalAcceptanceExport> Ready and failed rows, for the erasure of an account
     * @throws HilosException When the query fails
     */
    public function allFinished(): array
    {
        return $this->hydrateAll(static::entityClass()::get(
            '`state` IN (?, ?)',
            [LegalAcceptancesExportState::READY, LegalAcceptancesExportState::FAILED],
        ));
    }

    /**
     * @param EntityCollection<EntityLegalAcceptanceExport> $entities Rows returned by the database
     * @return list<ObjectLegalAcceptanceExport> Order rows in query order
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
