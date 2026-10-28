<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\StatusSubscribers as EntityStatusSubscribers;
use Hilos\Database\Entity\Item\StatusSubscriber as EntityStatusSubscriber;
use Hilos\Database\Object\Item\StatusSubscriber as ObjectStatusSubscriber;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\StatusSubscribers as ViewStatusSubscribers;
use Hilos\HilosException;
use Hilos\Status\StatusSubscriberCountsData;

/**
 * @extends Objects<ObjectStatusSubscriber>
 * @method ObjectStatusSubscriber|null first()
 * @method ObjectStatusSubscriber|null last()
 * @method ObjectStatusSubscriber|null get(int|string $key)
 * @method ObjectStatusSubscriber|null offsetGet(mixed $offset)
 */
class StatusSubscribers extends Objects
{
    public const string OBJECT_CLASS = ObjectStatusSubscriber::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStatusSubscribers::class;
    public const string COLLECTION_KEY = HilosDbContext::statusSubscribers;

    /**
     * @param string $email Address normalized at this lookup boundary
     * @return ?ObjectStatusSubscriber Matching subscription
     * @throws HilosException When the lookup cannot be executed
     */
    public function findByEmail(string $email): ?ObjectStatusSubscriber
    {
        foreach ($this->loadByColumn(EntityStatusSubscriber::email, mb_strtolower(trim($email))) as $id) {
            return $this->objects[$id];
        }
        return null;
    }

    /**
     * @param string $token Presented raw confirmation value
     * @return ?ObjectStatusSubscriber Matching row; expiry and spending are checked by its atomic confirm action
     * @throws HilosException When the lookup fails
     */
    public function findByConfirmationToken(string $token): ?ObjectStatusSubscriber
    {
        return $this->findBySecret(EntityStatusSubscriber::confirmation_token_hash, hash('sha256', $token));
    }

    /**
     * @param string $token Presented permanent unsubscribe value
     * @return ?ObjectStatusSubscriber Matching subscription without either secret
     * @throws HilosException When the lookup fails
     */
    public function findByUnsubscribeToken(string $token): ?ObjectStatusSubscriber
    {
        return $this->findBySecret(EntityStatusSubscriber::unsubscribe_token, $token);
    }

    /**
     * @return StatusSubscriberCountsData Fresh counts and captured upper id without hydrating the audience
     * @throws DatabaseException When the aggregate query fails or returns no row
     */
    public function counts(): StatusSubscriberCountsData
    {
        $row = Database::sql(
            'SELECT COUNT(*) AS `total`, COALESCE(SUM(`confirmed` = 1), 0) AS `confirmed`, '
                . 'COALESCE(MAX(`id`), 0) AS `max_id` FROM ' . static::entityClass()::getDatabase()->getSqlTableName(EntityStatusSubscriber::_table),
            index: static::entityClass()::getDatabase()->connectionIndex,
        )->first()?->first() ?? throw new DatabaseException('Subscriber aggregate returned no row');
        return new StatusSubscriberCountsData(
            (int)$row['total'], (int)$row['confirmed'], (int)$row['total'] - (int)$row['confirmed'], (int)$row['max_id'],
        );
    }

    /**
     * @param int $eventAt Audience cutoff in UTC epoch milliseconds
     * @param int $maxSubscriberId Captured maximum id at the start of the pass
     * @param ?int $afterId Last processed id, null for the first portion
     * @param int $limit Requested rows, capped at 32; nonpositive reads are empty
     * @return static Detached ascending-id selection, leaving the source cache bounded
     * @throws HilosException When query execution or selection initialization fails
     */
    public function confirmedRecipients(int $eventAt, int $maxSubscriberId, ?int $afterId, int $limit): static
    {
        $selection = static::initEmpty();
        $limit = max(0, min(ViewStatusSubscribers::MAX_PORTION_ROWS, $limit));
        if ($limit === 0) {
            return $selection;
        }
        $rows = static::entityClass()::get( // fresh-sql: the shared column loader cannot bound a cutoff and keyset together.
            filters: '`confirmed` = 1 AND `confirmed_at` <= ? AND `id` > ? AND `id` <= ?',
            filtersParam: [$eventAt, $afterId ?? 0, $maxSubscriberId],
            orderBy: [EntityStatusSubscriber::id => SqlSortDirection::ASC],
            limit: $limit,
        );
        foreach ($rows as $id => $entity) {
            $selection->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
        }
        return $selection;
    }

    /**
     * @param TableQueryDTO $query Subscriber search and optional strict confirmed filter
     * @return array{0: string, 1: list<SqlParam>} Shared condition for windows, facets and membership
     * @throws TableSearchNotSupportedException When a term has no searchable fields
     * @throws TableSearchFieldUnknownException When a search field is not a column
     * @throws ValidationException When the confirmed choice is not a boolean
     */
    protected function searchCondition(TableQueryDTO $query): array
    {
        [$condition, $params] = parent::searchCondition($query);
        if (!array_key_exists(EntityStatusSubscriber::confirmed, $query->filter)) {
            return [$condition, $params];
        }
        if (!is_bool($query->filter[EntityStatusSubscriber::confirmed])) {
            throw new ValidationException('Choose whether the subscriber address is confirmed.');
        }
        $condition = $condition === '' ? 'confirmed = ?' : "({$condition}) AND confirmed = ?";
        $params[] = SqlParam::bool($query->filter[EntityStatusSubscriber::confirmed]);
        return [$condition, $params];
    }

    /**
     * @param string $column One of the two DB-only token columns, chosen internally
     * @param string $secret Presented token or its hash
     * @return ?ObjectStatusSubscriber Fresh row snapshot without retaining secrets
     * @throws HilosException When query execution fails
     */
    private function findBySecret(string $column, string $secret): ?ObjectStatusSubscriber
    {
        $rows = static::entityClass()::get( // fresh-sql: DB-only secrets are deliberately absent from the in-memory column loader.
            filters: [$column => $secret], limit: 1,
        );
        foreach ($rows as $id => $entity) {
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            return $this->objects[$id];
        }
        return null;
    }
}
