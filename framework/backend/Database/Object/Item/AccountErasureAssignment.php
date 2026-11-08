<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\AccountErasureAssignment as EntityAccountErasureAssignment;
use Hilos\Database\Object\Collection\AccountErasureAssignments as ObjectAccountErasureAssignments;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\DatabaseException;

/**
 * @extends Object_<EntityAccountErasureAssignment>
 * @property-read ?int $id
 * @property-read int $operationId
 * @property-read string $stage
 * @property-read string $assignmentKey
 * @property-read string $ownerType
 * @property-read ?string $ownerIndex
 * @property-read ?int $userId
 * @property-read string $payloadJson
 * @property-read int $createdAt
 */
class AccountErasureAssignment extends Object_
{
    public const string ENTITY_CLASS = EntityAccountErasureAssignment::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountErasureAssignments::class;
    public const string id = 'id';
    public const string operationId = 'operationId';
    public const string stage = 'stage';
    public const string assignmentKey = 'assignmentKey';
    public const string ownerType = 'ownerType';
    public const string ownerIndex = 'ownerIndex';
    public const string userId = 'userId';
    public const string payloadJson = 'payloadJson';
    public const string createdAt = 'createdAt';

    /**
     * @param string $property Stored field name
     * @return mixed Scalar stored value
     * @throws DatabaseException When the field is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::operationId => $this->entity->operation_id,
            self::stage => $this->entity->stage,
            self::assignmentKey => $this->entity->assignment_key,
            self::ownerType => $this->entity->owner_type,
            self::ownerIndex => $this->entity->owner_index,
            self::userId => $this->entity->user_id,
            self::payloadJson => $this->entity->payload_json,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /** @return array<string, mixed> Stored fields with object keys */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::operationId => $this->entity->operation_id,
            self::stage => $this->entity->stage,
            self::assignmentKey => $this->entity->assignment_key,
            self::ownerType => $this->entity->owner_type,
            self::ownerIndex => $this->entity->owner_index,
            self::userId => $this->entity->user_id,
            self::payloadJson => $this->entity->payload_json,
            self::createdAt => $this->entity->created_at,
        ];
    }

    /**
     * Announces the conditional insert without saving a second row; rollback restores the unsaved object.
     *
     * @param int $id Key assigned by the collection's conditional insert
     * @throws DatabaseException When the binding or announcement fails
     * @throws InvalidArgumentException When the sync signal is invalid
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When the inserted row has no id
     */
    public function acceptStoredInsert(int $id): void
    {
        $before = $this->entity->toArray();
        $stored = $before;
        $stored[EntityAccountErasureAssignment::id] = $id;
        $this->restoreSaved($stored, related: true);
        Database::onRollback(function () use ($before): void {
            $this->restoreSaved($before, related: false);
        }, index: $this->entity::getDatabase()->connectionIndex);
        $this->broadcastDbSyncAfterSync(true, $stored, []);
    }
}
