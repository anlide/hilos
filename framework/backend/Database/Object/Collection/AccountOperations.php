<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
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
use Hilos\Database\Entity\Collection\AccountOperations as EntityAccountOperations;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\AccountOperation as ObjectAccountOperation;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;
use JsonException;

/**
 * @extends Objects<ObjectAccountOperation>
 * @method ObjectAccountOperation|null first()
 * @method ObjectAccountOperation|null last()
 * @method ObjectAccountOperation|null get(int|string $key)
 * @method ObjectAccountOperation|null offsetGet(mixed $offset)
 */
class AccountOperations extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountOperation::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountOperations::class;
    public const string COLLECTION_KEY = HilosDbContext::accountOperations;
    public const int PAGE_LIMIT_MAX = 1000;

    /**
     * The coordinator may record a request once, or repeat exactly what it already recorded.
     *
     * @param int $requestId Original deletion request id
     * @param int $rootUserId Immutable root account id
     * @param AccountOperationCircle $circle Checked fixed account order
     * @return ObjectAccountOperation Persisted operation
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws ValidationException When the supplied values conflict or exceed storage limits
     * @throws WriteNotAllowedException When the current writer lacks the requested right
     * @throws CreateNotAllowedException When the storing sync refuses creation
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function recordErasure(int $requestId, int $rootUserId, AccountOperationCircle $circle): ObjectAccountOperation
    {
        Database::assertCanWrite(EntityAccountOperation::getDatabase()->connectionIndex);
        DbWriteGuard::guardCollectionWrite(static::COLLECTION_KEY, TruthSourceOperation::Add);
        if ($requestId <= 0 || $rootUserId !== $circle->rootUserId) {
            throw new ValidationException('The request and root must identify the supplied account operation circle.');
        }
        $existing = $this->byRequest($requestId);
        if ($existing !== null) {
            return $this->matchRecord($existing, $rootUserId, $circle);
        }

        $entity = static::entityClass()::getEmpty();
        $entity->kind = AccountOperationKind::Erasure->value;
        $entity->request_id = $requestId;
        $entity->root_user_id = $rootUserId;
        $entity->circle_user_ids = json_encode($circle->toArray());
        $entity->stage = EntityAccountOperation::INITIAL_STAGE;
        $entity->created_at = TimeHelper::nowMs();
        $entity->updated_at = $entity->created_at;
        $operation = static::OBJECT_CLASS::fromEntity($entity);
        try {
            $operation->sync();
        } catch (DuplicateEntryException $failure) {
            if (preg_match(
                "/for key '(?:[^']+\\.)?" . EntityAccountOperation::REQUEST_UNIQUE_KEY . "'$/",
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
            throw new DatabaseException('Account operation insert did not assign an id');
        }
        $this[$operation->id] = $operation;

        return $operation;
    }

    /**
     * SCAFFOLD: durable merge opening for the future coordinator; no live merge caller yet.
     *
     * @param int $survivorUserId Immutable survivor account id
     * @param int $loserUserId Immutable loser account id and open-operation identity
     * @param AccountMergeChoice $choice Immutable fates and supplied protection flags
     * @return ObjectAccountOperation New operation or its exact open predecessor
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws ValidationException When the pair is malformed or differs from the open operation
     * @throws WriteNotAllowedException When the current writer lacks the requested right
     * @throws CreateNotAllowedException When the storing sync refuses creation
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function recordMerge(int $survivorUserId, int $loserUserId, AccountMergeChoice $choice): ObjectAccountOperation
    {
        Database::assertCanWrite(EntityAccountOperation::getDatabase()->connectionIndex);
        DbWriteGuard::guardCollectionWrite(static::COLLECTION_KEY, TruthSourceOperation::Add);
        if ($survivorUserId <= 0 || $loserUserId <= 0 || $survivorUserId === $loserUserId) {
            throw new ValidationException('The merge requires two different positive account ids.');
        }
        $existing = $this->openMergeOfLoser($loserUserId);
        if ($existing !== null) {
            return $this->matchMerge($existing, $survivorUserId, $choice);
        }

        $entity = static::entityClass()::getEmpty();
        $entity->kind = AccountOperationKind::Merge->value;
        $entity->request_id = null;
        $entity->root_user_id = $survivorUserId;
        $entity->loser_user_id = $loserUserId;
        $entity->open_loser_user_id = $loserUserId;
        $entity->merge_choice = $choice->toJson();
        $entity->circle_user_ids = json_encode([$loserUserId, $survivorUserId]);
        $entity->stage = AccountMergeStage::Preparing->value;
        $entity->created_at = TimeHelper::nowMs();
        $entity->updated_at = $entity->created_at;
        $operation = static::OBJECT_CLASS::fromEntity($entity);
        try {
            $operation->sync();
        } catch (DuplicateEntryException $failure) {
            if (preg_match("/for key '(?:[^']+\\.)?uq_account_operation_open_loser'$/", $failure->getMysqlErrorMessage()) !== 1) {
                throw $failure;
            }
            $existing = $this->currentMergeOfLoser($loserUserId);
            if ($existing === null) {
                throw $failure;
            }

            return $this->matchMerge($existing, $survivorUserId, $choice);
        }
        if ($operation->id === null) {
            throw new DatabaseException('Account merge operation insert did not assign an id');
        }
        $this[$operation->id] = $operation;

        return $operation;
    }

    /**
     * @param int $loserUserId Open merge's immutable loser account id
     * @return ?ObjectAccountOperation Open merge for this loser
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function openMergeOfLoser(int $loserUserId): ?ObjectAccountOperation
    {
        $entities = static::entityClass()::get( // fresh-sql: another coordinator attempt may have opened or finished this loser.
            [EntityAccountOperation::open_loser_user_id => $loserUserId],
            limit: 1,
        );
        $entity = $entities->first();

        return $entity === null ? null : $this->hold($entity);
    }

    /**
     * @param int $requestId Original deletion request id
     * @return ?ObjectAccountOperation Operation recorded for that request
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function byRequest(int $requestId): ?ObjectAccountOperation
    {
        $entities = static::entityClass()::get( // fresh-sql: exact repeats compare persisted identity even before sync frames arrive.
            [EntityAccountOperation::request_id => $requestId],
            limit: 1,
        );
        $entity = $entities->first();

        return $entity === null ? null : $this->hold($entity);
    }

    /**
     * Reads current storage; any shared row lock lasts within the caller's transaction.
     *
     * @param int $operationId Persisted operation id
     * @return ?ObjectAccountOperation Current row using this mounted chain
     * @throws DatabaseException When the row lookup or database binding fails
     * @throws InvalidArgumentException When the row query is invalid
     * @throws SourceChangeSubscriberException When a source mirror fails during rollback registration
     * @throws ObjectGetIdStringNotImplementedException When an existing row has no id
     */
    public function fresh(int $operationId): ?ObjectAccountOperation
    {
        $rows = Database::sql(
            'SELECT * FROM ' . static::entityClass()::getSqlTableName() . ' WHERE `id` = ? LOCK IN SHARE MODE',
            [$operationId],
            index: static::entityClass()::getDatabase()->connectionIndex,
        )->rows();

        return $rows === [] ? null : $this->hold(static::entityClass()::fromRow($rows[0]));
    }

    /**
     * @param AccountOperationKind $kind Required operation kind
     * @param int $afterId Exclusive primary id cursor
     * @param int $limit Maximum rows in this page
     * @return static Open operations in ascending primary id order
     * @throws HilosException When the manual page cannot be initialized
     */
    public function openPage(AccountOperationKind $kind, int $afterId, int $limit): static
    {
        self::validatePage($afterId, $limit);
        $entities = static::entityClass()::get( // fresh-sql: open membership must reflect persisted completion, not a partial mirror.
            '`kind` = ? AND `finished_at` IS NULL AND `id` > ?',
            [$kind->value, $afterId],
            [EntityAccountOperation::id => SqlSortDirection::ASC],
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
     * Walks every page of open erasures in primary id order until one circle holds the account.
     *
     * @param int $userId Account asked about
     * @return ?ObjectAccountOperation First open erasure whose fixed circle contains the account
     * @throws HilosException When a page cannot be read or a stored circle is malformed
     */
    public function openErasureCovering(int $userId): ?ObjectAccountOperation
    {
        $afterId = 0;
        do {
            $page = $this->openPage(AccountOperationKind::Erasure, $afterId, self::PAGE_LIMIT_MAX);
            foreach ($page as $operationId => $operation) {
                if ($operation->circle()->contains($userId)) {
                    return $operation;
                }
                $afterId = (int)$operationId;
            }
        } while (count($page) === self::PAGE_LIMIT_MAX);

        return null;
    }

    /**
     * @param int $afterId Exclusive primary id cursor
     * @param int $limit Maximum rows in this page
     * @throws ValidationException When the cursor or page size is invalid
     */
    public static function validatePage(int $afterId, int $limit): void
    {
        if ($afterId < 0 || $limit < 1 || $limit > self::PAGE_LIMIT_MAX) {
            throw new ValidationException('The account operation page requires a nonnegative cursor and a bounded positive limit.');
        }
    }

    /**
     * A persistence CAS only: the caller proves any business permission for the transition.
     *
     * @param ObjectAccountOperation $operation Already issued object to synchronize
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
    public function advance(ObjectAccountOperation $operation, string $expectedStage, string $nextStage): bool
    {
        $this->guardUpdate($operation);
        ObjectAccountOperation::validateStage($expectedStage);
        ObjectAccountOperation::validateStage($nextStage);
        if ($expectedStage === $nextStage) {
            $this->refresh($operation);

            return false;
        }
        Database::sql(
            'UPDATE ' . static::entityClass()::getSqlTableName()
            . ' SET `stage` = ?, `updated_at` = ? WHERE `id` = ? AND `stage` = ? AND `finished_at` IS NULL',
            [$nextStage, TimeHelper::nowMs(), $operation->id, $expectedStage],
            tryReconnect: false,
            index: EntityAccountOperation::getDatabase()->connectionIndex,
        );
        $changed = Database::affectedRows(EntityAccountOperation::getDatabase()->connectionIndex) === 1;
        $this->refresh($operation, $changed);

        return $changed;
    }

    /**
     * @param ObjectAccountOperation $operation Already issued object to synchronize
     * @param string $expectedStage Required current stage
     * @param AccountOperationOutcome $outcome Persisted terminal outcome
     * @return bool Whether this statement closed the operation
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws ValidationException When the supplied values conflict or exceed storage limits
     * @throws WriteNotAllowedException When the current writer lacks the requested right
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function finish(
        ObjectAccountOperation $operation,
        string $expectedStage,
        AccountOperationOutcome $outcome,
    ): bool {
        $this->guardUpdate($operation);
        ObjectAccountOperation::validateStage($expectedStage);
        $now = TimeHelper::nowMs();
        Database::sql(
            'UPDATE ' . static::entityClass()::getSqlTableName()
            . ' SET `outcome` = ?, `finished_at` = ?, `updated_at` = ?, `open_loser_user_id` = NULL'
            . ' WHERE `id` = ? AND `stage` = ? AND `finished_at` IS NULL',
            [$outcome->value, $now, $now, $operation->id, $expectedStage],
            tryReconnect: false,
            index: EntityAccountOperation::getDatabase()->connectionIndex,
        );
        $changed = Database::affectedRows(EntityAccountOperation::getDatabase()->connectionIndex) === 1;
        $this->refresh($operation, $changed);

        return $changed;
    }

    /**
     * Keeps the safe reason an account's final was refused; a repeat for the same account replaces it.
     *
     * @param ObjectAccountOperation $operation Already issued object to synchronize
     * @param int $userId Account of the operation whose final was refused
     * @param ?string $table Table still referencing the person, or null when the refusal named none
     * @return bool Whether this statement kept the reason on the still open operation
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws ValidationException When the stored refusals are malformed
     * @throws JsonException When the table name is not valid UTF-8
     * @throws WriteNotAllowedException When the current writer lacks the requested right
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    public function noteFinalRefusal(ObjectAccountOperation $operation, int $userId, ?string $table): bool
    {
        $this->guardUpdate($operation);
        $refusals = $operation->finalRefusals;
        $refusals[$userId] = $table;
        Database::sql(
            'UPDATE ' . static::entityClass()::getSqlTableName()
            . ' SET `final_refusals` = ?, `updated_at` = ? WHERE `id` = ? AND `finished_at` IS NULL',
            [
                json_encode($refusals, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                TimeHelper::nowMs(),
                $operation->id,
            ],
            tryReconnect: false,
            index: EntityAccountOperation::getDatabase()->connectionIndex,
        );
        $changed = Database::affectedRows(EntityAccountOperation::getDatabase()->connectionIndex) === 1;
        $this->refresh($operation, $changed);

        return $changed;
    }

    /**
     * @param ObjectAccountOperation $operation Target object
     * @throws DatabaseException When storage or its binding fails
     * @throws WriteNotAllowedException When the current writer lacks the requested right
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    private function guardUpdate(ObjectAccountOperation $operation): void
    {
        Database::assertCanWrite(EntityAccountOperation::getDatabase()->connectionIndex);
        DbWriteGuard::guardItemWrite(
            static::COLLECTION_KEY,
            $operation->getIdString(),
            $operation->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );
    }

    /**
     * @param ObjectAccountOperation $operation Object whose existing wrappers must remain current
     * @param bool $announce Whether a conditional write changed the row
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    private function refresh(ObjectAccountOperation $operation, bool $announce = false): void
    {
        $entity = static::entityClass()::getById($operation->id);
        if ($entity !== null) {
            $operation->acceptStored($entity, $announce);
        }
    }

    /**
     * @param EntityAccountOperation $entity Fresh persisted row
     * @return ObjectAccountOperation Existing object identity, or a quietly hydrated row
     * @throws DatabaseException When storage or its binding fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a saved row has no id
     */
    private function hold(EntityAccountOperation $entity): ObjectAccountOperation
    {
        if ($entity->id === null) {
            throw new DatabaseException('Stored account operation has no id');
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
     * @return ?ObjectAccountOperation Current winner through the mounted chain
     * @throws DatabaseException When the current read or binding fails
     * @throws InvalidArgumentException When the sync signal is invalid
     * @throws SourceChangeSubscriberException When a rollback mirror fails
     * @throws ObjectGetIdStringNotImplementedException When the stored row has no id
     */
    private function currentByRequest(int $requestId): ?ObjectAccountOperation
    {
        $rows = Database::sql(
            'SELECT * FROM ' . static::entityClass()::getSqlTableName() . ' WHERE `request_id` = ? LOCK IN SHARE MODE',
            [$requestId],
            index: static::entityClass()::getDatabase()->connectionIndex,
        )->rows();

        return $rows === [] ? null : $this->hold(static::entityClass()::fromRow($rows[0]));
    }

    /**
     * @param int $loserUserId Contended open merge identity
     * @return ?ObjectAccountOperation Current winner beyond the caller's consistent snapshot
     * @throws DatabaseException When the current read or binding fails
     * @throws InvalidArgumentException When the sync signal is invalid
     * @throws SourceChangeSubscriberException When a rollback mirror fails
     * @throws ObjectGetIdStringNotImplementedException When the stored row has no id
     */
    private function currentMergeOfLoser(int $loserUserId): ?ObjectAccountOperation
    {
        $rows = Database::sql(
            'SELECT * FROM ' . static::entityClass()::getSqlTableName() . ' WHERE `open_loser_user_id` = ? LOCK IN SHARE MODE',
            [$loserUserId],
            index: static::entityClass()::getDatabase()->connectionIndex,
        )->rows();

        return $rows === [] ? null : $this->hold(static::entityClass()::fromRow($rows[0]));
    }

    /**
     * @param ObjectAccountOperation $existing Open merge for the repeated loser
     * @param int $survivorUserId Repeated survivor id
     * @param AccountMergeChoice $choice Repeated immutable choices
     * @return ObjectAccountOperation Exact previous merge
     * @throws ValidationException When the survivor or choices differ, or stored choices are invalid
     */
    private function matchMerge(
        ObjectAccountOperation $existing,
        int $survivorUserId,
        AccountMergeChoice $choice,
    ): ObjectAccountOperation {
        if ($existing->survivorUserId !== $survivorUserId || !$existing->mergeChoice()->equals($choice)) {
            throw new ValidationException('This account already has an open merge with different choices.');
        }

        return $existing;
    }

    /**
     * @param ObjectAccountOperation $existing Persisted operation
     * @param int $rootUserId Repeated root id
     * @param AccountOperationCircle $circle Repeated fixed circle
     * @return ObjectAccountOperation Exact previous record
     * @throws ValidationException When the request was recorded with different immutable data
     */
    private function matchRecord(
        ObjectAccountOperation $existing,
        int $rootUserId,
        AccountOperationCircle $circle,
    ): ObjectAccountOperation {
        if ($existing->rootUserId !== $rootUserId || $existing->circle()->toArray() !== $circle->toArray()) {
            throw new ValidationException('This deletion request already has a different account operation.');
        }

        return $existing;
    }
}
