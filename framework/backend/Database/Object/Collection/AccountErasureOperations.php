<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\AccountErasure\AccountErasureCircle;
use Hilos\Auth\AccountErasure\AccountErasureOutcome;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\AccountErasureOperations as EntityAccountErasureOperations;
use Hilos\Database\Entity\Item\AccountErasureOperation as EntityAccountErasureOperation;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\AccountErasureOperation as ObjectAccountErasureOperation;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * @extends Objects<ObjectAccountErasureOperation>
 * @method ObjectAccountErasureOperation|null first()
 * @method ObjectAccountErasureOperation|null last()
 * @method ObjectAccountErasureOperation|null get(int|string $key)
 * @method ObjectAccountErasureOperation|null offsetGet(mixed $offset)
 */
class AccountErasureOperations extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountErasureOperation::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountErasureOperations::class;
    public const string COLLECTION_KEY = HilosDbContext::accountErasureOperations;
    public const int PAGE_LIMIT_MAX = 1000;

    /**
     * The coordinator may record a request once, or repeat exactly what it already recorded.
     *
     * @param int $requestId Original deletion request id
     * @param int $rootUserId Immutable root account id
     * @param AccountErasureCircle $circle Checked fixed account order
     * @return ObjectAccountErasureOperation Persisted operation
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws ValidationException When the supplied values conflict or exceed storage limits
     * @throws WriteNotAllowedException When the current writer lacks the requested right
     * @throws CreateNotAllowedException When the storing sync refuses creation
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function record(int $requestId, int $rootUserId, AccountErasureCircle $circle): ObjectAccountErasureOperation
    {
        Database::assertCanWrite(EntityAccountErasureOperation::getDatabase()->connectionIndex);
        DbWriteGuard::guardCollectionWrite(static::COLLECTION_KEY, TruthSourceOperation::Add);
        if ($requestId <= 0 || $rootUserId !== $circle->rootUserId) {
            throw new ValidationException('The request and root must identify the supplied erasure circle.');
        }
        $existing = $this->byRequest($requestId);
        if ($existing !== null) {
            return $this->matchRecord($existing, $rootUserId, $circle);
        }

        $entity = static::entityClass()::getEmpty();
        $entity->request_id = $requestId;
        $entity->root_user_id = $rootUserId;
        $entity->circle_user_ids = json_encode($circle->toArray());
        $entity->stage = EntityAccountErasureOperation::INITIAL_STAGE;
        $entity->created_at = TimeHelper::nowMs();
        $entity->updated_at = $entity->created_at;
        $operation = static::OBJECT_CLASS::fromEntity($entity);
        try {
            $operation->sync();
        } catch (DuplicateEntryException $failure) {
            if (preg_match(
                "/for key '(?:[^']+\\.)?" . EntityAccountErasureOperation::REQUEST_UNIQUE_KEY . "'$/",
                $failure->getMysqlErrorMessage(),
            ) !== 1) {
                throw $failure;
            }
            $existing = $this->currentByRequest($requestId);
            if ($existing === null) {
                throw $failure;
            }

            return $this->matchRecord($existing, $rootUserId, $circle);
        }
        if ($operation->id === null) {
            throw new DatabaseException('Erasure operation insert did not assign an id');
        }
        $this[$operation->id] = $operation;

        return $operation;
    }

    /**
     * @param int $requestId Original deletion request id
     * @return ?ObjectAccountErasureOperation Operation recorded for that request
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function byRequest(int $requestId): ?ObjectAccountErasureOperation
    {
        $entities = static::entityClass()::get( // fresh-sql: exact repeats compare persisted identity even before sync frames arrive.
            [EntityAccountErasureOperation::request_id => $requestId],
            limit: 1,
        );
        $entity = $entities->first();

        return $entity === null ? null : $this->hold($entity);
    }

    /**
     * Reads current storage; any shared row lock lasts within the caller's transaction.
     *
     * @param int $operationId Persisted operation id
     * @return ?ObjectAccountErasureOperation Current row using this mounted chain
     * @throws DatabaseException When the row lookup or database binding fails
     * @throws InvalidArgumentException When the row query is invalid
     * @throws SourceChangeSubscriberException When a source mirror fails during rollback registration
     * @throws ObjectGetIdStringNotImplementedException When an existing row has no id
     */
    public function fresh(int $operationId): ?ObjectAccountErasureOperation
    {
        $rows = Database::sql(
            'SELECT * FROM ' . static::entityClass()::getSqlTableName() . ' WHERE `id` = ? LOCK IN SHARE MODE',
            [$operationId],
            index: static::entityClass()::getDatabase()->connectionIndex,
        )->rows();

        return $rows === [] ? null : $this->hold(static::entityClass()::fromRow($rows[0]));
    }

    /**
     * @param int $afterId Exclusive primary id cursor
     * @param int $limit Maximum rows in this page
     * @return static Open operations in ascending primary id order
     * @throws HilosException When the manual page cannot be initialized
     */
    public function openPage(int $afterId, int $limit): static
    {
        self::validatePage($afterId, $limit);
        $entities = static::entityClass()::get( // fresh-sql: open membership must reflect persisted completion, not a partial mirror.
            '`finished_at` IS NULL AND `id` > ?',
            [$afterId],
            [EntityAccountErasureOperation::id => SqlSortDirection::ASC],
            $limit,
        );
        $page = static::initEmpty();
        foreach ($entities as $entity) {
            $object = $this->hold($entity);
            $page->hydrate($entity->id, $object);
        }

        return $page;
    }

    /**
     * @param int $afterId Exclusive primary id cursor
     * @param int $limit Maximum rows in this page
     * @throws ValidationException When the cursor or page size is invalid
     */
    public static function validatePage(int $afterId, int $limit): void
    {
        if ($afterId < 0 || $limit < 1 || $limit > self::PAGE_LIMIT_MAX) {
            throw new ValidationException('The erasure page requires a nonnegative cursor and a bounded positive limit.');
        }
    }

    /**
     * A persistence CAS only: the caller proves any business permission for the transition.
     *
     * @param ObjectAccountErasureOperation $operation Already issued object to synchronize
     * @param string $expectedStage Required current stage
     * @param string $nextStage Literal next stage
     * @return bool Whether this statement moved the stage
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws ValidationException When the supplied values conflict or exceed storage limits
     * @throws WriteNotAllowedException When the current writer lacks the requested right
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function advance(ObjectAccountErasureOperation $operation, string $expectedStage, string $nextStage): bool
    {
        $this->guardUpdate($operation);
        ObjectAccountErasureOperation::validateStage($expectedStage);
        ObjectAccountErasureOperation::validateStage($nextStage);
        if ($expectedStage === $nextStage) {
            $this->refresh($operation);

            return false;
        }
        Database::sql(
            'UPDATE ' . static::entityClass()::getSqlTableName()
            . ' SET `stage` = ?, `updated_at` = ? WHERE `id` = ? AND `stage` = ? AND `finished_at` IS NULL',
            [$nextStage, TimeHelper::nowMs(), $operation->id, $expectedStage],
            tryReconnect: false,
            index: EntityAccountErasureOperation::getDatabase()->connectionIndex,
        );
        $changed = Database::affectedRows(EntityAccountErasureOperation::getDatabase()->connectionIndex) === 1;
        $this->refresh($operation, $changed);

        return $changed;
    }

    /**
     * @param ObjectAccountErasureOperation $operation Already issued object to synchronize
     * @param string $expectedStage Required current stage
     * @param AccountErasureOutcome $outcome Persisted terminal outcome
     * @return bool Whether this statement closed the operation
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws ValidationException When the supplied values conflict or exceed storage limits
     * @throws WriteNotAllowedException When the current writer lacks the requested right
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function finish(
        ObjectAccountErasureOperation $operation,
        string $expectedStage,
        AccountErasureOutcome $outcome,
    ): bool {
        $this->guardUpdate($operation);
        ObjectAccountErasureOperation::validateStage($expectedStage);
        $now = TimeHelper::nowMs();
        Database::sql(
            'UPDATE ' . static::entityClass()::getSqlTableName()
            . ' SET `outcome` = ?, `finished_at` = ?, `updated_at` = ?'
            . ' WHERE `id` = ? AND `stage` = ? AND `finished_at` IS NULL',
            [$outcome->value, $now, $now, $operation->id, $expectedStage],
            tryReconnect: false,
            index: EntityAccountErasureOperation::getDatabase()->connectionIndex,
        );
        $changed = Database::affectedRows(EntityAccountErasureOperation::getDatabase()->connectionIndex) === 1;
        $this->refresh($operation, $changed);

        return $changed;
    }

    /**
     * @param ObjectAccountErasureOperation $operation Target object
     * @throws DatabaseException When storage or its binding fails
     * @throws WriteNotAllowedException When the current writer lacks the requested right
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    private function guardUpdate(ObjectAccountErasureOperation $operation): void
    {
        Database::assertCanWrite(EntityAccountErasureOperation::getDatabase()->connectionIndex);
        DbWriteGuard::guardItemWrite(
            static::COLLECTION_KEY,
            $operation->getIdString(),
            $operation->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );
    }

    /**
     * @param ObjectAccountErasureOperation $operation Object whose existing wrappers must remain current
     * @param bool $announce Whether a conditional write changed the row
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    private function refresh(ObjectAccountErasureOperation $operation, bool $announce = false): void
    {
        $entity = static::entityClass()::getById($operation->id);
        if ($entity !== null) {
            $operation->acceptStored($entity, $announce);
        }
    }

    /**
     * @param EntityAccountErasureOperation $entity Fresh persisted row
     * @return ObjectAccountErasureOperation Existing object identity, or a quietly hydrated row
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    private function hold(EntityAccountErasureOperation $entity): ObjectAccountErasureOperation
    {
        if ($entity->id === null) {
            throw new DatabaseException('Stored erasure operation has no id');
        }
        if (isset($this->objects[$entity->id])) {
            $this->objects[$entity->id]->acceptStored($entity);
        } else {
            $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }

    /**
     * A UNIQUE collision observes current storage even when the caller already owns an older snapshot.
     *
     * @param int $requestId Contended immutable request identity
     * @return ?ObjectAccountErasureOperation Current winner through the mounted chain
     * @throws DatabaseException When the current read or binding fails
     * @throws InvalidArgumentException When the sync signal is invalid
     * @throws SourceChangeSubscriberException When a rollback mirror fails
     * @throws ObjectGetIdStringNotImplementedException When the stored row has no id
     */
    private function currentByRequest(int $requestId): ?ObjectAccountErasureOperation
    {
        $rows = Database::sql(
            'SELECT * FROM ' . static::entityClass()::getSqlTableName() . ' WHERE `request_id` = ? LOCK IN SHARE MODE',
            [$requestId],
            index: static::entityClass()::getDatabase()->connectionIndex,
        )->rows();

        return $rows === [] ? null : $this->hold(static::entityClass()::fromRow($rows[0]));
    }

    /**
     * @param ObjectAccountErasureOperation $existing Persisted operation
     * @param int $rootUserId Repeated root id
     * @param AccountErasureCircle $circle Repeated fixed circle
     * @return ObjectAccountErasureOperation Exact previous record
     * @throws ValidationException When the request was recorded with different immutable data
     */
    private function matchRecord(
        ObjectAccountErasureOperation $existing,
        int $rootUserId,
        AccountErasureCircle $circle,
    ): ObjectAccountErasureOperation {
        if ($existing->rootUserId !== $rootUserId || $existing->circle()->toArray() !== $circle->toArray()) {
            throw new ValidationException('This deletion request already has a different erasure operation.');
        }

        return $existing;
    }
}
