<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\AccountOperationResults as EntityAccountOperationResults;
use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;
use Hilos\Database\Entity\Item\AccountOperationResult as EntityAccountOperationResult;
use Hilos\Database\Object\Item\AccountOperationResult as ObjectAccountOperationResult;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * @extends Objects<ObjectAccountOperationResult>
 * @method ObjectAccountOperationResult|null first()
 * @method ObjectAccountOperationResult|null last()
 * @method ObjectAccountOperationResult|null get(int|string $key)
 * @method ObjectAccountOperationResult|null offsetGet(mixed $offset)
 */
class AccountOperationResults extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountOperationResult::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountOperationResults::class;
    public const string COLLECTION_KEY = HilosDbContext::accountOperationResults;

    /**
     * @param int $assignmentId Immutable parent assignment
     * @param string $ownerAgentId Exact participant address used as the set key
     * @param AccountOperationResultPayload $payload Opaque participant result
     * @return ObjectAccountOperationResult Newly stored immutable row
     * @throws HilosException When the insert, ownership guard or announcement fails
     */
    public function record(int $assignmentId, string $ownerAgentId, AccountOperationResultPayload $payload): ObjectAccountOperationResult
    {
        $entity = static::entityClass()::getEmpty();
        $entity->assignment_id = $assignmentId;
        $entity->owner_agent_id = $ownerAgentId;
        $entity->payload_json = $payload->toJson();
        $entity->created_at = TimeHelper::nowMs();
        $record = static::OBJECT_CLASS::fromEntity($entity);
        $record->sync();
        if ($record->id === null) {
            throw new DatabaseException('Account account operation result insert did not assign an id');
        }
        $this[$record->id] = $record;

        return $record;
    }

    /**
     * @param int $assignmentId Assignment primary id
     * @return ?ObjectAccountOperationResult Saved result, or null before completion
     * @throws HilosException When the column lookup cannot load the stored row
     */
    public function byAssignment(int $assignmentId): ?ObjectAccountOperationResult
    {
        foreach ($this->loadByColumn(EntityAccountOperationResult::assignment_id, $assignmentId) as $id) {
            return $this->objects[$id];
        }

        return null;
    }

    /**
     * @param int $operationId Parent operation primary id
     * @param string $stage Exact assignment stage
     * @return static Results in ascending result primary id order
     * @throws HilosException When the persisted selection or hydration fails
     */
    public function forOperationStage(int $operationId, string $stage): static
    {
        $rows = Database::sql(
            'SELECT r.* FROM ' . static::entityClass()::getSqlTableName() . ' r'
            . ' JOIN ' . EntityAccountOperationAssignment::getSqlTableName() . ' a ON a.`id` = r.`assignment_id`'
            . ' WHERE a.`operation_id` = ? AND a.`stage` = ? ORDER BY r.`id` ASC',
            [$operationId, $stage],
            index: static::entityClass()::getDatabase()->connectionIndex,
        )->rows();
        $selection = static::initEmpty();
        foreach ($rows as $row) {
            $entity = static::entityClass()::fromRow($row);
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $selection->hydrate($entity->id, $this->objects[$entity->id]);
        }

        return $selection;
    }
}
