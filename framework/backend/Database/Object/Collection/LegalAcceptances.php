<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\LegalAcceptances as EntityLegalAcceptances;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\LegalAcceptance as EntityLegalAcceptance;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\LegalAcceptance as ObjectLegalAcceptance;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Legal\LegalDocument;

/**
 * Persisted acceptance queries, including rows not held in the lazy cache.
 *
 * @extends Objects<ObjectLegalAcceptance>
 */
class LegalAcceptances extends Objects
{
    public const string OBJECT_CLASS = ObjectLegalAcceptance::class;
    public const string ENTITY_COLLECTION_CLASS = EntityLegalAcceptances::class;
    public const string COLLECTION_KEY = HilosDbContext::legalAcceptances;

    /**
     * @param int $userId Person accepting
     * @param LegalDocument $document Declared document
     * @param string $revisionId Exact revision accepted
     * @param string $acceptedAt Server timestamp, SQL format
     * @return ObjectLegalAcceptance New record
     * @throws DatabaseException When the insert fails
     * @throws CreateNotAllowedException When this process cannot create the row
     * @throws WriteNotAllowedException When the storing sync is refused
     * @throws SourceChangeSubscriberException When a subscriber to the store announcement fails
     * @throws InvalidArgumentException When the DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException When the inserted row has no primary key
     */
    public function record(int $userId, LegalDocument $document, string $revisionId, string $acceptedAt): ObjectLegalAcceptance
    {
        $acceptance = static::OBJECT_CLASS::create();
        $acceptance->userId = $userId;
        $acceptance->document = $document->value;
        $acceptance->revisionId = $revisionId;
        $acceptance->acceptedAt = $acceptedAt;
        $acceptance->sync();
        $id = $acceptance->id;
        if ($id === null) {
            throw new DatabaseException('Legal acceptance insert did not assign an id');
        }
        $this[$id] = $acceptance;

        return $acceptance;
    }

    /**
     * @param int $userId Person whose history to read
     * @return list<ObjectLegalAcceptance> Acceptance rows in timestamp/id order for the projection boundary
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query order is invalid
     */
    public function ofUser(int $userId): array
    {
        return $this->hydrateAll(static::entityClass()::get(
            [EntityLegalAcceptance::user_id => $userId],
            [],
            [EntityLegalAcceptance::accepted_at => SqlSortDirection::ASC, EntityLegalAcceptance::id => SqlSortDirection::ASC],
        ));
    }

    /**
     * @param int $userId Person accepting
     * @param LegalDocument $document Document
     * @param string $revisionId Revision key
     * @return ?ObjectLegalAcceptance Prior acceptance of this exact revision
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query order is invalid
     */
    public function findOne(int $userId, LegalDocument $document, string $revisionId): ?ObjectLegalAcceptance
    {
        return $this->hydrateAll(static::entityClass()::get([
            EntityLegalAcceptance::user_id => $userId,
            EntityLegalAcceptance::document => $document->value,
            EntityLegalAcceptance::revision_id => $revisionId,
        ]))[0] ?? null;
    }

    /**
     * @param int $userId Person being erased
     * @throws DatabaseException When a query or delete fails
     * @throws InvalidArgumentException When a query or DB-sync signal is invalid
     * @throws WriteNotAllowedException When the removal is refused
     * @throws SourceChangeSubscriberException When a subscriber to the removal fails
     */
    public function deleteForUser(int $userId): void
    {
        foreach ($this->ofUser($userId) as $acceptance) {
            $id = $acceptance->id;
            $acceptance->delete();
            unset($this[$id]);
        }
    }

    /**
     * @param EntityCollection<EntityLegalAcceptance> $entities Queried rows
     * @return list<ObjectLegalAcceptance> Loaded rows in query order
     */
    private function hydrateAll(EntityCollection $entities): array
    {
        $acceptances = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $acceptances[] = $this->objects[$entity->id];
        }

        return $acceptances;
    }
}
