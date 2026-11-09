<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Entity\Item\AccountErasureResult as EntityAccountErasureResult;
use Hilos\Database\Object\Collection\AccountErasureResults as ObjectAccountErasureResults;
use Hilos\Database\DatabaseException;

/**
 * @extends Object_<EntityAccountErasureResult>
 * @property-read ?int $id
 * @property-read int $assignmentId
 * @property-read string $ownerAgentId
 * @property-read string $payloadJson
 * @property-read int $createdAt
 */
class AccountErasureResult extends Object_
{
    public const string ENTITY_CLASS = EntityAccountErasureResult::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountErasureResults::class;
    public const string id = 'id';
    public const string assignmentId = 'assignmentId';
    public const string ownerAgentId = 'ownerAgentId';
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
            self::assignmentId => $this->entity->assignment_id,
            self::ownerAgentId => $this->entity->owner_agent_id,
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
            self::assignmentId => $this->entity->assignment_id,
            self::ownerAgentId => $this->entity->owner_agent_id,
            self::payloadJson => $this->entity->payload_json,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
