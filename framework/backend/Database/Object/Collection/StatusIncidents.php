<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSearchTerm;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\StatusIncidents as EntityStatusIncidents;
use Hilos\Database\Object\Item\StatusIncident as ObjectStatusIncident;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\StatusIncidents as ViewStatusIncidents;
use Hilos\HilosException;
use Hilos\Status\DTO\StatusHistoryAnchor;
use Hilos\Status\DTO\StatusHistoryEntry;
use Hilos\Status\DTO\StatusHistoryPortion;
use Hilos\Status\DTO\StatusHistoryQuery;

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

    private const string HISTORY_COMPLETED_CONDITION = '`resolved_at` IS NOT NULL';

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

    /**
     * Fresh SQL snapshots do not enter the lazy ORM source map.
     *
     * @param StatusHistoryQuery $query Shared filters, complete global anchor and bounded prefix
     * @return StatusHistoryPortion Canonical source order, excluding the edge probe; merged skip is left to the caller
     * @throws DatabaseException When the source query or completed-row projection fails
     * @throws ValidationException When the bounded snapshot cannot be formed
     */
    public function historyPortion(StatusHistoryQuery $query): StatusHistoryPortion
    {
        $binding = static::entityClass()::getDatabase();
        $table = $binding->getSqlTableName($this->getTableName());
        [$condition, $params] = $this->historyCondition($query);
        $order = $query->readsAscending() ? SqlSortDirection::ASC : SqlSortDirection::DESC;
        $limit = $query->sourceLimit();
        $rows = Database::sql(
            'SELECT `id`, `component_key`, `component_name`, `severity`, `peak_severity`,'
                . ' `detected_at`, `resolved_at`, `published_at`, `outcome`'
                . " FROM {$table} WHERE {$condition} ORDER BY `detected_at` {$order}, `id` {$order} LIMIT {$limit}",
            $params, index: $binding->connectionIndex,
        )->rows();
        $hasMore = count($rows) === $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        if ($query->direction === TableAnchorDirection::Before) {
            $rows = array_reverse($rows);
        }
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = StatusHistoryEntry::fromIncidentRow($row);
        }
        return new StatusHistoryPortion($entries, $hasMore);
    }

    /**
     * The anchor, when supplied, counts the same strict tuple side as historyPortion().
     * edgeSkip and limit do not change the counted set.
     *
     * @param StatusHistoryQuery $query Shared source predicate and optional tuple side
     * @return TableFacetCountDTO Exact count through COUNT_CEILING, or the ceiling with exact=false
     * @throws DatabaseException When the scalar query fails
     */
    public function historyCount(StatusHistoryQuery $query): TableFacetCountDTO
    {
        $binding = static::entityClass()::getDatabase();
        $table = $binding->getSqlTableName($this->getTableName());
        [$condition, $params] = $this->historyCondition($query);
        $limit = TableConstants::COUNT_CEILING + 1;
        $count = (int)(Database::sql(
            "SELECT COUNT(*) AS total_count FROM (SELECT 1 FROM {$table} WHERE {$condition} LIMIT {$limit}) capped",
            $params, index: $binding->connectionIndex,
        )->field('total_count') ?? throw new DatabaseException('History aggregate returned no row'));
        return new TableFacetCountDTO(min($count, TableConstants::COUNT_CEILING), $count <= TableConstants::COUNT_CEILING);
    }

    /**
     * @param int $id Known source primary key
     * @param StatusHistoryQuery $query Same completed/filter/search predicate as the window
     * @return bool Whether this source row belongs to the queried set
     * @throws DatabaseException When storage cannot be read
     */
    public function historyContains(int $id, StatusHistoryQuery $query): bool
    {
        $binding = static::entityClass()::getDatabase();
        $table = $binding->getSqlTableName($this->getTableName());
        [$condition, $params] = $this->historyCondition($query);
        $params[] = $id;
        return Database::sql("SELECT `id` FROM {$table} WHERE {$condition} AND `id` = ? LIMIT 1",
            $params, index: $binding->connectionIndex)->rows() !== [];
    }

    /**
     * @return int All completed source facts, independent of UI filters, without row hydration
     * @throws DatabaseException When the scalar query fails
     */
    public function completedCount(): int
    {
        $binding = static::entityClass()::getDatabase();
        return (int)(Database::sql(
            'SELECT COUNT(*) AS total_count FROM ' . $binding->getSqlTableName($this->getTableName())
                . ' WHERE ' . self::HISTORY_COMPLETED_CONDITION,
            index: $binding->connectionIndex,
        )->field('total_count') ?? throw new DatabaseException('History aggregate returned no row'));
    }

    /**
     * @return list<string> Distinct historical component keys of completed facts, without hydrating source rows
     * @throws DatabaseException When the scalar key query fails
     */
    public function historyComponentKeys(): array
    {
        $binding = static::entityClass()::getDatabase();
        $table = $binding->getSqlTableName($this->getTableName());
        $rows = Database::sql(
            "SELECT DISTINCT `component_key` FROM {$table} WHERE " . self::HISTORY_COMPLETED_CONDITION
                . ' ORDER BY `component_key`',
            index: $binding->connectionIndex,
        )->rows();
        return array_map(static fn(array $row): string => (string)$row['component_key'], $rows);
    }

    /**
     * @param StatusHistoryQuery $query One predicate for windows, counts and tuple-side counts
     * @return array{string, list<mixed>} SQL boundary clause and bound scalar parameters
     */
    private function historyCondition(StatusHistoryQuery $query): array
    {
        $conditions = [self::HISTORY_COMPLETED_CONDITION];
        $params = [];
        if ($query->term !== null) {
            $conditions[] = '`component_name` ' . TableSearchTerm::LIKE_COMPARISON;
            $params[] = TableSearchTerm::likePattern($query->term);
        }
        if ($query->componentKey !== null) {
            $conditions[] = '`component_key` = ?';
            $params[] = $query->componentKey;
        }
        if ($query->outcome !== null) {
            $conditions[] = '`outcome` = ?';
            $params[] = $query->outcome;
        }
        if ($query->fromMs !== null) {
            $conditions[] = '`detected_at` >= ?';
            $params[] = $query->fromMs;
        }
        if ($query->toExclusiveMs !== null) {
            $conditions[] = '`detected_at` < ?';
            $params[] = $query->toExclusiveMs;
        }
        if ($query->anchor !== null) {
            $operator = $query->readsAscending() ? '>' : '<';
            if ($query->anchor->kindRank === StatusHistoryAnchor::INCIDENT_RANK) {
                $conditions[] = "(`detected_at` {$operator} ? OR (`detected_at` = ? AND `id` {$operator} ?))";
                array_push($params, $query->anchor->startedAt, $query->anchor->startedAt, $query->anchor->sourceId);
            } else {
                $rankAfter = StatusHistoryAnchor::INCIDENT_RANK > $query->anchor->kindRank;
                if ($rankAfter === $query->readsAscending()) {
                    $operator .= '=';
                }
                $conditions[] = "`detected_at` {$operator} ?";
                $params[] = $query->anchor->startedAt;
            }
        }
        return [implode(' AND ', $conditions), $params];
    }
}
