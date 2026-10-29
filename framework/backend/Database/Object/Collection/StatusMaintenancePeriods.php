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
use Hilos\Database\Entity\Collection\StatusMaintenancePeriods as EntityStatusMaintenancePeriods;
use Hilos\Database\Entity\Item\StatusMaintenancePeriod as EntityStatusMaintenancePeriod;
use Hilos\Database\Object\Item\StatusMaintenancePeriod as ObjectStatusMaintenancePeriod;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\StatusMaintenancePeriods as ViewStatusMaintenancePeriods;
use Hilos\HilosException;
use Hilos\Status\DTO\StatusHistoryAnchor;
use Hilos\Status\DTO\StatusHistoryEntry;
use Hilos\Status\DTO\StatusHistoryPortion;
use Hilos\Status\DTO\StatusHistoryQuery;

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

    private const string HISTORY_COMPLETED_CONDITION = '`completion_observed_at` IS NOT NULL';

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
            'SELECT `id`, `work_public_id`, `announcement_known`, `title`, `component_keys_json`,'
                . ' `started_at`, `ended_at`, `completion_observed_at`'
                . " FROM {$table} WHERE {$condition} ORDER BY `started_at` {$order}, `id` {$order} LIMIT {$limit}",
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
            $entries[] = StatusHistoryEntry::fromMaintenanceRow($row);
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
            "SELECT DISTINCT k.`component_key` FROM {$table}"
                . " CROSS JOIN JSON_TABLE(`component_keys_json`, '$[*]' COLUMNS (`component_key` VARCHAR(128) PATH '$')) k"
                . ' WHERE ' . self::HISTORY_COMPLETED_CONDITION . ' ORDER BY k.`component_key`',
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
            $conditions[] = '`title` ' . TableSearchTerm::LIKE_COMPARISON;
            $params[] = TableSearchTerm::likePattern($query->term);
        }
        if ($query->componentKey !== null) {
            $conditions[] = 'JSON_CONTAINS(`component_keys_json`, JSON_QUOTE(?))';
            $params[] = $query->componentKey;
        }
        if ($query->outcome !== null) {
            if ($query->outcome !== StatusHistoryEntry::MAINTENANCE_COMPLETED) {
                $conditions[] = '0 = 1';
            }
        }
        if ($query->fromMs !== null) {
            $conditions[] = '`started_at` >= ?';
            $params[] = $query->fromMs;
        }
        if ($query->toExclusiveMs !== null) {
            $conditions[] = '`started_at` < ?';
            $params[] = $query->toExclusiveMs;
        }
        if ($query->anchor !== null) {
            $operator = $query->readsAscending() ? '>' : '<';
            if ($query->anchor->kindRank === StatusHistoryAnchor::MAINTENANCE_RANK) {
                $conditions[] = "(`started_at` {$operator} ? OR (`started_at` = ? AND `id` {$operator} ?))";
                array_push($params, $query->anchor->startedAt, $query->anchor->startedAt, $query->anchor->sourceId);
            } else {
                $rankAfter = StatusHistoryAnchor::MAINTENANCE_RANK > $query->anchor->kindRank;
                if ($rankAfter === $query->readsAscending()) {
                    $operator .= '=';
                }
                $conditions[] = "`started_at` {$operator} ?";
                $params[] = $query->anchor->startedAt;
            }
        }
        return [implode(' AND ', $conditions), $params];
    }
}
