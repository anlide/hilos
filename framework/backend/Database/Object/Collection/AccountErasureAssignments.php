<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\AccountErasure\AccountErasureAssignmentPayload;
use Hilos\Auth\AccountErasure\AccountErasureOwnerAddress;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\AccountErasureAssignments as EntityAccountErasureAssignments;
use Hilos\Database\Entity\Item\AccountErasureAssignment as EntityAccountErasureAssignment;
use Hilos\Database\Entity\Item\AccountErasureOperation as EntityAccountErasureOperation;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Item\AccountErasureAssignment as ObjectAccountErasureAssignment;
use Hilos\Database\Object\Item\AccountErasureOperation as ObjectAccountErasureOperation;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * @extends Objects<ObjectAccountErasureAssignment>
 * @method ObjectAccountErasureAssignment|null first()
 * @method ObjectAccountErasureAssignment|null last()
 * @method ObjectAccountErasureAssignment|null get(int|string $key)
 * @method ObjectAccountErasureAssignment|null offsetGet(mixed $offset)
 */
class AccountErasureAssignments extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountErasureAssignment::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountErasureAssignments::class;
    public const string COLLECTION_KEY = HilosDbContext::accountErasureAssignments;

    /**
     * Guarded before the repeat lookup; addressed participants receive no write right.
     *
     * @param int $operationId Parent operation id
     * @param string $stage Immutable stage key
     * @param string $assignmentKey Immutable part key within the stage
     * @param AccountErasureOwnerAddress $owner Stable participant address
     * @param ?int $userId Fixed-circle account, or null for the entire circle
     * @param AccountErasureAssignmentPayload $payload Immutable JSON object
     * @return ObjectAccountErasureAssignment Stored assignment, including an exact old or closed repeat
     * @throws HilosException When the current operation or a manual selection cannot be read
     */
    public function record(
        int $operationId,
        string $stage,
        string $assignmentKey,
        AccountErasureOwnerAddress $owner,
        ?int $userId,
        AccountErasureAssignmentPayload $payload,
    ): ObjectAccountErasureAssignment {
        Database::assertCanWrite(EntityAccountErasureAssignment::getDatabase()->connectionIndex);
        DbWriteGuard::guardCollectionWrite(static::COLLECTION_KEY, TruthSourceOperation::Add);
        self::validateIdentity($stage, $assignmentKey);
        $existing = $this->byIdentity($operationId, $stage, $assignmentKey);
        if ($existing !== null) {
            return $this->matchRecord($existing, $owner, $userId, $payload);
        }
        $operations = Hilos::$db->accountErasureOperations->getObjectCollection();
        $operation = $operations->fresh($operationId);
        if ($operation === null || $operation->finishedAt !== null || $operation->stage !== $stage) {
            $existing = $this->currentByIdentity($operationId, $stage, $assignmentKey);
            if ($existing !== null) {
                return $this->matchRecord($existing, $owner, $userId, $payload);
            }
            throw new ValidationException('A new assignment requires the current stage of an open erasure operation.');
        }
        if ($userId !== null && !$operation->circle()->contains($userId)) {
            throw new ValidationException('The assigned account must belong to the fixed erasure circle.');
        }

        $entity = static::entityClass()::getEmpty();
        $entity->operation_id = $operationId;
        $entity->stage = $stage;
        $entity->assignment_key = $assignmentKey;
        $entity->owner_type = $owner->ownerType;
        $entity->owner_index = $owner->ownerIndex;
        $entity->user_id = $userId;
        $entity->payload_json = $payload->toJson();
        $entity->created_at = TimeHelper::nowMs();
        $assignment = static::OBJECT_CLASS::fromEntity($entity);
        DbWriteGuard::guardCreate(static::COLLECTION_KEY, $assignment->touchedSetKeys(...));
        // SQL boundary: include every mounted Entity column, including project extension defaults.
        $row = $entity->toArray();
        unset($row[EntityAccountErasureAssignment::id]);
        $columns = '`' . implode('`, `', array_keys($row)) . '`';
        $values = implode(', ', array_fill(0, count($row), '?'));
        $index = static::entityClass()::getDatabase()->connectionIndex;
        try {
            // The parent is read current and locked in the INSERT itself. Its CAS cannot fit
            // between admission and insertion, including when this call runs in autocommit.
            Database::sql(
                'INSERT INTO ' . static::entityClass()::getSqlTableName() . " ({$columns}) SELECT {$values}"
                . ' FROM ' . $operations::entityClass()::getSqlTableName()
                . ' WHERE `id` = ? AND `stage` = ? AND `finished_at` IS NULL LOCK IN SHARE MODE',
                [...array_values($row), $operationId, $stage],
                tryReconnect: false,
                index: $index,
            );
        } catch (DuplicateEntryException $failure) {
            if (preg_match(
                "/for key '(?:[^']+\\.)?" . EntityAccountErasureAssignment::IDENTITY_UNIQUE_KEY . "'$/",
                $failure->getMysqlErrorMessage(),
            ) !== 1) {
                throw $failure;
            }
            $existing = $this->currentByIdentity($operationId, $stage, $assignmentKey);
            if ($existing === null) {
                throw $failure;
            }

            return $this->matchRecord($existing, $owner, $userId, $payload);
        }
        if (Database::affectedRows($index) === 0) {
            $existing = $this->currentByIdentity($operationId, $stage, $assignmentKey);
            if ($existing !== null) {
                return $this->matchRecord($existing, $owner, $userId, $payload);
            }
            throw new ValidationException('A new assignment requires the current stage of an open erasure operation.');
        }
        $assignment->acceptStoredInsert(Database::lastInsertId($index));
        if ($assignment->id === null) {
            throw new DatabaseException('Erasure assignment insert did not assign an id');
        }
        $this[$assignment->id] = $assignment;

        return $assignment;
    }

    /**
     * @param string $stage Literal stage key
     * @param string $assignmentKey Literal part identity
     * @throws ValidationException When a key is empty or too long
     */
    public static function validateIdentity(string $stage, string $assignmentKey): void
    {
        ObjectAccountErasureOperation::validateStage($stage);
        if ($assignmentKey === '' || mb_strlen($assignmentKey) > EntityAccountErasureAssignment::ASSIGNMENT_KEY_MAX_CHARACTERS) {
            throw new ValidationException('The erasure assignment key must be nonempty and fit its storage limit.');
        }
    }

    /**
     * @param int $operationId Parent operation id
     * @param string $stage Literal stage key
     * @param string $assignmentKey Literal part identity
     * @return ?ObjectAccountErasureAssignment Matching immutable assignment
     * @throws HilosException When the current operation or a manual selection cannot be read
     */
    public function byIdentity(int $operationId, string $stage, string $assignmentKey): ?ObjectAccountErasureAssignment
    {
        self::validateIdentity($stage, $assignmentKey);
        return $this->select(
            '`operation_id` = ? AND `stage` = ? AND `assignment_key` = ?',
            [$operationId, $stage, $assignmentKey],
            1,
        )->first();
    }

    /**
     * @param int $operationId Parent operation id
     * @param string $stage Literal stage key
     * @return static Assignments in ascending primary id order
     * @throws HilosException When the current operation or a manual selection cannot be read
     */
    public function forOperationStage(int $operationId, string $stage): static
    {
        ObjectAccountErasureOperation::validateStage($stage);
        return $this->select('`operation_id` = ? AND `stage` = ?', [$operationId, $stage]);
    }

    /**
     * @param AccountErasureOwnerAddress $owner Exact participant address, including literal nullable index
     * @param int $afterId Exclusive primary id cursor
     * @param int $limit Maximum rows in this page
     * @return static Assignments of open operations in ascending primary id order
     * @throws HilosException When the current operation or a manual selection cannot be read
     */
    public function openForOwner(AccountErasureOwnerAddress $owner, int $afterId, int $limit): static
    {
        AccountErasureOperations::validatePage($afterId, $limit);
        $condition = '`owner_type` = ? AND `owner_index` <=> ? AND `id` > ? AND `operation_id` IN ('
            . 'SELECT `id` FROM ' . EntityAccountErasureOperation::getSqlTableName() . ' WHERE `finished_at` IS NULL)';

        return $this->select($condition, [$owner->ownerType, $owner->ownerIndex, $afterId], $limit);
    }

    /**
     * @param string $condition Bound SQL predicate for persisted assignment membership
     * @param array<int, mixed> $params SQL boundary parameters
     * @param int $limit Optional bounded page size; zero means the selected operation/stage set
     * @return static Quietly hydrated selection in primary id order
     * @throws HilosException When the current operation or a manual selection cannot be read
     */
    private function select(string $condition, array $params, int $limit = 0): static
    {
        $entities = static::entityClass()::get( // fresh-sql: owner/open and immutable repeat membership must come from persisted rows.
            $condition,
            $params,
            [EntityAccountErasureAssignment::id => SqlSortDirection::ASC],
            $limit,
        );
        $selection = static::initEmpty();
        foreach ($entities as $entity) {
            $selection->hydrate($entity->id, $this->hold($entity));
        }

        return $selection;
    }

    /**
     * @param int $operationId Parent identity
     * @param string $stage Immutable stage
     * @param string $assignmentKey Contended part identity
     * @return ?ObjectAccountErasureAssignment Current winner, independent of a caller's consistent snapshot
     * @throws HilosException When the current row cannot be read or hydrated
     */
    private function currentByIdentity(int $operationId, string $stage, string $assignmentKey): ?ObjectAccountErasureAssignment
    {
        $rows = Database::sql(
            'SELECT * FROM ' . static::entityClass()::getSqlTableName()
            . ' WHERE `operation_id` = ? AND `stage` = ? AND `assignment_key` = ? LOCK IN SHARE MODE',
            [$operationId, $stage, $assignmentKey],
            index: static::entityClass()::getDatabase()->connectionIndex,
        )->rows();

        return $rows === [] ? null : $this->hold(static::entityClass()::fromRow($rows[0]));
    }

    /**
     * @param EntityAccountErasureAssignment $entity Persisted immutable row
     * @return ObjectAccountErasureAssignment Existing identity or a silently hydrated object
     * @throws HilosException When the stored row has no id or hydration fails
     */
    private function hold(EntityAccountErasureAssignment $entity): ObjectAccountErasureAssignment
    {
        if ($entity->id === null) {
            throw new DatabaseException('Stored erasure assignment has no id');
        }
        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }

    /**
     * @param ObjectAccountErasureAssignment $existing Persisted immutable row
     * @param AccountErasureOwnerAddress $owner Repeated address
     * @param ?int $userId Repeated fixed-circle account
     * @param AccountErasureAssignmentPayload $payload Repeated JSON object
     * @return ObjectAccountErasureAssignment Exact original record
     * @throws ValidationException When any immutable content differs
     * @throws InvalidFormatException When the stored JSON is not an object
     * @throws InvalidJsonException When stored JSON is malformed
     */
    private function matchRecord(
        ObjectAccountErasureAssignment $existing,
        AccountErasureOwnerAddress $owner,
        ?int $userId,
        AccountErasureAssignmentPayload $payload,
    ): ObjectAccountErasureAssignment {
        if ($existing->ownerType !== $owner->ownerType || $existing->ownerIndex !== $owner->ownerIndex
            || $existing->userId !== $userId
            || !AccountErasureAssignmentPayload::fromJson($existing->payloadJson)->equals($payload)) {
            throw new ValidationException('This erasure assignment already contains different immutable work.');
        }

        return $existing;
    }
}
