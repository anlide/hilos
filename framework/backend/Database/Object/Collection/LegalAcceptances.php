<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
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
     * Aggregates in SQL; unknown revisions do not give a person a held declaration.
     *
     * @param string $document Stored document key
     * @param list<string> $declaredIds Revision keys in declaration order, from the catalog boundary
     * @return array<string, int> People by their latest accepted declared revision
     * @throws DatabaseException When the histogram query fails
     */
    public function heldCounts(string $document, array $declaredIds): array
    {
        if ($declaredIds === []) {
            return [];
        }
        $cases = [];
        foreach ($declaredIds as $index => $id) {
            $cases[] = 'WHEN ? THEN ' . ($index + 1);
        }
        $counts = [];
        foreach (Database::sql(
            'SELECT held, COUNT(*) AS people FROM (SELECT MAX(CASE revision_id '
            . implode(' ', $cases) . ' ELSE 0 END) AS held FROM ' . EntityLegalAcceptance::_table
            . ' WHERE document = ? GROUP BY user_id) AS holders WHERE held > 0 GROUP BY held ORDER BY held',
            [...$declaredIds, $document],
        )->rows() as $row) {
            $counts[$declaredIds[(int) $row['held'] - 1]] = (int) $row['people'];
        }

        return $counts;
    }

    /**
     * The same judgement as {@see self::heldCounts()}, named by person instead of counted.
     *
     * @param string $document Stored document key
     * @param list<string> $declaredIds Revision keys in declaration order, from the catalog boundary
     * @return array<int, string> Latest accepted declared revision per person, keyed by user id
     * @throws DatabaseException When the query fails
     */
    public function heldByUser(string $document, array $declaredIds): array
    {
        if ($declaredIds === []) {
            return [];
        }
        $cases = [];
        foreach ($declaredIds as $index => $id) {
            $cases[] = 'WHEN ? THEN ' . ($index + 1);
        }
        $held = [];
        foreach (Database::sql(
            'SELECT user_id, MAX(CASE revision_id ' . implode(' ', $cases) . ' ELSE 0 END) AS held FROM '
            . EntityLegalAcceptance::_table . ' WHERE document = ? GROUP BY user_id HAVING held > 0 ORDER BY user_id',
            [...$declaredIds, $document],
        )->rows() as $row) {
            $held[(int) $row[EntityLegalAcceptance::user_id]] = $declaredIds[(int) $row['held'] - 1];
        }

        return $held;
    }

    /**
     * @param string $document Stored document key, including an undeclared document
     * @return array<string, int> Persisted record counts by revision, without hydrating objects
     * @throws DatabaseException When the histogram query fails
     */
    public function acceptedCounts(string $document): array
    {
        $counts = [];
        foreach (Database::sql(
            'SELECT revision_id, COUNT(*) AS acceptances FROM ' . EntityLegalAcceptance::_table
            . ' WHERE document = ? GROUP BY revision_id ORDER BY revision_id',
            [$document],
        )->rows() as $row) {
            $counts[(string) $row[EntityLegalAcceptance::revision_id]] = (int) $row['acceptances'];
        }

        return $counts;
    }

    /**
     * @return list<string> Distinct persisted document keys, including undeclared documents
     * @throws DatabaseException When the document query fails
     */
    public function documentsOnRecord(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row[EntityLegalAcceptance::document],
            Database::sql('SELECT DISTINCT document FROM ' . EntityLegalAcceptance::_table . ' ORDER BY document')->rows(),
        );
    }

    /**
     * @return array<string, list<string>> Distinct recorded revision keys per document, without object hydration
     * @throws DatabaseException When the revision query fails
     */
    public function revisionsOnRecord(): array
    {
        $revisions = [];
        foreach (Database::sql(
            'SELECT document, revision_id FROM ' . EntityLegalAcceptance::_table
            . ' GROUP BY document, revision_id ORDER BY document, revision_id',
        )->rows() as $row) {
            $revisions[(string) $row[EntityLegalAcceptance::document]][] = (string) $row[EntityLegalAcceptance::revision_id];
        }

        return $revisions;
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
     * @param int $userId Person whose acceptance rows are selectively removed
     * @param LegalDocument $document Document narrowed to
     * @param list<string> $revisionIds Revisions whose acceptance rows are removed
     * @return list<string> Deleted revision ids
     * @throws DatabaseException When a query or delete fails
     * @throws InvalidArgumentException When a query or DB-sync signal is invalid
     * @throws WriteNotAllowedException When the removal is refused
     * @throws SourceChangeSubscriberException When a subscriber to the removal fails
     */
    public function deleteOfRevisions(int $userId, LegalDocument $document, array $revisionIds): array
    {
        $deleted = [];
        foreach ($this->ofUser($userId) as $acceptance) {
            if ($acceptance->document === $document->value && in_array($acceptance->revisionId, $revisionIds, true)) {
                $id = $acceptance->id;
                $acceptance->delete();
                unset($this[$id]);
                $deleted[] = $acceptance->revisionId;
            }
        }

        return $deleted;
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
