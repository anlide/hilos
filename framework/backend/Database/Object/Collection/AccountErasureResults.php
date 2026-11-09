<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\AccountErasure\AccountErasureResultPayload;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\AccountErasureResults as EntityAccountErasureResults;
use Hilos\Database\Entity\Item\AccountErasureAssignment as EntityAccountErasureAssignment;
use Hilos\Database\Entity\Item\AccountErasureResult as EntityAccountErasureResult;
use Hilos\Database\Object\Item\AccountErasureResult as ObjectAccountErasureResult;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * @extends Objects<ObjectAccountErasureResult>
 * @method ObjectAccountErasureResult|null first()
 * @method ObjectAccountErasureResult|null last()
 * @method ObjectAccountErasureResult|null get(int|string $key)
 * @method ObjectAccountErasureResult|null offsetGet(mixed $offset)
 */
class AccountErasureResults extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountErasureResult::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountErasureResults::class;
    public const string COLLECTION_KEY = HilosDbContext::accountErasureResults;

    /**
     * @param int $assignmentId Immutable parent assignment
     * @param string $ownerAgentId Exact participant address used as the set key
     * @param AccountErasureResultPayload $payload Opaque participant result
     * @return ObjectAccountErasureResult Newly stored immutable row
     * @throws HilosException When the insert, ownership guard or announcement fails
     */
    public function record(int $assignmentId, string $ownerAgentId, AccountErasureResultPayload $payload): ObjectAccountErasureResult
    {
        $entity = static::entityClass()::getEmpty();
        $entity->assignment_id = $assignmentId;
        $entity->owner_agent_id = $ownerAgentId;
        $entity->payload_json = $payload->toJson();
        $entity->created_at = TimeHelper::nowMs();
        $record = static::OBJECT_CLASS::fromEntity($entity);
        $record->sync();
        if ($record->id === null) {
            throw new DatabaseException('Account erasure result insert did not assign an id');
        }
        $this[$record->id] = $record;

        return $record;
    }

    /**
     * @param int $assignmentId Assignment primary id
     * @return ?ObjectAccountErasureResult Saved result, or null before completion
     * @throws HilosException When the column lookup cannot load the stored row
     */
    public function byAssignment(int $assignmentId): ?ObjectAccountErasureResult
    {
        foreach ($this->loadByColumn(EntityAccountErasureResult::assignment_id, $assignmentId) as $id) {
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
            . ' JOIN ' . EntityAccountErasureAssignment::getSqlTableName() . ' a ON a.`id` = r.`assignment_id`'
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
