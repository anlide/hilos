<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\StatusWorks as EntityStatusWorks;
use Hilos\Database\Entity\Item\StatusWork as EntityStatusWork;
use Hilos\Database\Entity\Item\StatusWorkComponent as EntityStatusWorkComponent;
use Hilos\Database\Object\Item\StatusWork as ObjectStatusWork;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\StatusWorks as ViewStatusWorks;
use Hilos\HilosException;
use Hilos\Status\StatusWorksSummaryData;

/**
 * @extends Objects<ObjectStatusWork>
 * @method ObjectStatusWork|null first()
 * @method ObjectStatusWork|null last()
 * @method ObjectStatusWork|null get(int|string $key)
 * @method ObjectStatusWork|null offsetGet(mixed $offset)
 */
class StatusWorks extends Objects
{
    public const string OBJECT_CLASS = ObjectStatusWork::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStatusWorks::class;
    public const string COLLECTION_KEY = HilosDbContext::statusWorks;

    /**
     * @param string $publicId Immutable public identity
     * @return ?ObjectStatusWork Matching persisted work
     * @throws HilosException When the lookup cannot be executed
     */
    public function findByPublicId(string $publicId): ?ObjectStatusWork
    {
        foreach ($this->loadByColumn(EntityStatusWork::public_id, $publicId) as $id) {
            return $this->objects[$id];
        }
        return null;
    }

    /**
     * Adjacent half-open windows do not conflict; components do not partition the system window.
     *
     * @param int $startsAt Proposed inclusive start in UTC epoch milliseconds
     * @param int $endsAt Proposed exclusive end in UTC epoch milliseconds
     * @param ?int $excludeId Persisted work being changed, or null for creation
     * @return ?ObjectStatusWork First noncanceled conflicting work
     * @throws HilosException When the bounded lookup fails
     */
    public function conflictingWindow(int $startsAt, int $endsAt, ?int $excludeId = null): ?ObjectStatusWork
    {
        $rows = static::entityClass()::get( // fresh-sql: overlap plus an excluded id is not an equality-column read.
            filters: '`canceled_at` IS NULL AND `starts_at` < ? AND `ends_at` > ? AND `id` <> ?',
            filtersParam: [$endsAt, $startsAt, $excludeId ?? 0],
            orderBy: ['id' => SqlSortDirection::ASC],
            limit: 1,
        );
        foreach ($rows as $id => $entity) {
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            return $this->objects[$id];
        }
        return null;
    }

    /**
     * @param int $startedAt Observed maintenance start in UTC epoch milliseconds
     * @return ?ObjectStatusWork Unfinished announcement covering [start, end)
     * @throws HilosException When the bounded lookup fails
     */
    public function matchingAnnouncement(int $startedAt): ?ObjectStatusWork
    {
        $rows = static::entityClass()::get( // fresh-sql: interval and lifecycle predicates need a bounded compound read.
            filters: '`canceled_at` IS NULL AND `consumed_at` IS NULL AND `expired_at` IS NULL AND `starts_at` <= ? AND `ends_at` > ?',
            filtersParam: [$startedAt, $startedAt],
            orderBy: ['id' => SqlSortDirection::ASC],
            limit: 1,
        );
        foreach ($rows as $id => $entity) {
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            return $this->objects[$id];
        }
        return null;
    }

    /**
     * @param int $nowMs Current UTC epoch milliseconds
     * @param ?int $afterId Last processed primary id, null for the first portion
     * @param int $limit Requested rows, capped at 32; nonpositive reads are empty
     * @return static Detached unexpired unfinished works in ascending id order
     * @throws HilosException When the query or detached selection cannot be initialized
     */
    public function upcomingPortion(int $nowMs, ?int $afterId, int $limit): static
    {
        $selection = static::initEmpty();
        $limit = max(0, min(ViewStatusWorks::MAX_PORTION_ROWS, $limit));
        if ($limit === 0) {
            return $selection;
        }
        $rows = static::entityClass()::get( // fresh-sql: lifecycle, expiry boundary and keyset must be bounded together.
            filters: '`canceled_at` IS NULL AND `consumed_at` IS NULL AND `expired_at` IS NULL AND `ends_at` > ? AND `id` > ?',
            filtersParam: [$nowMs, $afterId ?? 0],
            orderBy: ['id' => SqlSortDirection::ASC],
            limit: $limit,
        );
        foreach ($rows as $id => $entity) {
            $selection->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
        }
        return $selection;
    }

    /**
     * No whole-table walk: successful item expiry removes the row from the next bounded selection.
     *
     * @param int $nowMs Expiry detection in UTC epoch milliseconds
     * @return static At most 32 unfinished overdue announcements in primary-id order
     * @throws HilosException When the query or selection initialization fails
     */
    public function expiredPortion(int $nowMs): static
    {
        $selection = static::initEmpty();
        $rows = static::entityClass()::get( // fresh-sql: overdue lifecycle predicates need a bounded compound read.
            filters: '`canceled_at` IS NULL AND `consumed_at` IS NULL AND `expired_at` IS NULL AND `ends_at` <= ?',
            filtersParam: [$nowMs],
            orderBy: ['id' => SqlSortDirection::ASC],
            limit: ViewStatusWorks::MAX_PORTION_ROWS,
        );
        foreach ($rows as $id => $entity) {
            $selection->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
        }
        return $selection;
    }

    /**
     * @param int $nowMs Current UTC epoch milliseconds
     * @return StatusWorksSummaryData Unexpired unfinished count and nearest start, without hydrating rows
     * @throws DatabaseException When the aggregate fails or returns no row
     */
    public function summary(int $nowMs): StatusWorksSummaryData
    {
        $binding = static::entityClass()::getDatabase();
        $row = Database::sql(
            'SELECT COUNT(*) AS `upcoming`, MIN(`starts_at`) AS `next_starts_at` FROM '
                . $binding->getSqlTableName(EntityStatusWork::_table)
                . ' WHERE `canceled_at` IS NULL AND `consumed_at` IS NULL AND `expired_at` IS NULL AND `ends_at` > ?',
            [$nowMs], index: $binding->connectionIndex,
        )->first()?->first() ?? throw new DatabaseException('Work aggregate returned no row');
        return new StatusWorksSummaryData((int)$row['upcoming'], $row['next_starts_at'] === null ? null : (int)$row['next_starts_at']);
    }

    /**
     * @param TableQueryDTO $query Work window, capped to one storage portion
     * @return array<string, mixed> Standard bounded ORM window and exact-or-capped counts
     * @throws DatabaseException When the database query fails
     * @throws InvalidArgumentException When an order direction is invalid
     * @throws TableSearchNotSupportedException When search has no declared fields
     * @throws TableSearchFieldUnknownException When a search field is unknown
     * @throws ValidationException When a component filter is malformed
     */
    public function queryPage(TableQueryDTO $query): array
    {
        $limit = $query->limit <= 0 ? ViewStatusWorks::MAX_PORTION_ROWS : min(ViewStatusWorks::MAX_PORTION_ROWS, $query->limit);
        return parent::queryPage($query->withLimit($limit));
    }

    /**
     * @param TableQueryDTO $query Search over title/description and optional catalog component key
     * @return array{0: string, 1: list<SqlParam>} One unfinished-work condition for windows, facets and membership
     * @throws TableSearchNotSupportedException When a term has no declared searchable fields
     * @throws TableSearchFieldUnknownException When a declared search field is unknown
     * @throws ValidationException When the component filter is not a nonempty code key
     */
    protected function searchCondition(TableQueryDTO $query): array
    {
        [$condition, $params] = parent::searchCondition($query);
        $pending = '`canceled_at` IS NULL AND `consumed_at` IS NULL AND `expired_at` IS NULL';
        $condition = $condition === '' ? $pending : "({$condition}) AND {$pending}";
        if (array_key_exists(EntityStatusWorkComponent::component_key, $query->filter)) {
            $key = $query->filter[EntityStatusWorkComponent::component_key];
            if (!is_string($key) || $key === '') {
                throw new ValidationException('Choose a status component.');
            }
            $condition .= ' AND EXISTS (SELECT 1 FROM `' . EntityStatusWorkComponent::_table
                . '` AS `selected_component` WHERE `selected_component`.`work_id` = `hilos_status_work`.`id`'
                . ' AND `selected_component`.`component_key` = ?)';
            $params[] = SqlParam::string($key);
        }
        return [$condition, $params];
    }
}
