<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Entity\Item\AccountMergeCopy as EntityAccountMergeCopy;
use Hilos\Database\Object\Collection\AccountMergeCopies as ObjectAccountMergeCopies;
use Hilos\Database\DatabaseException;
use Hilos\Database\Database;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\HilosException;
use JsonException;

/**
 * @extends Object_<EntityAccountMergeCopy>
 * @property-read int $assignmentId
 * @property-read string $ownerAgentId
 * @property-read int $createdAt
 */
class AccountMergeCopy extends Object_
{
    public const string ENTITY_CLASS = EntityAccountMergeCopy::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountMergeCopies::class;
    public const string assignmentId = 'assignmentId';
    public const string ownerAgentId = 'ownerAgentId';
    public const string createdAt = 'createdAt';

    /**
     * @param string $property Stored field name
     * @return mixed Scalar stored value
     * @throws DatabaseException When the field is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::assignmentId => $this->entity->assignment_id,
            self::ownerAgentId => $this->entity->owner_agent_id,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * Completes the insert inside its participant transaction; a filled payload can never be replaced.
     *
     * @internal Called only by the copy collection's birth path
     * @param AccountMergeHandoverCopy $copy Validated complete handover
     * @throws HilosException When the transaction, Add right, immutable birth or storage refuses the write
     * @throws JsonException When the copy cannot be encoded
     */
    public function writePayloadAtBirth(AccountMergeHandoverCopy $copy): void
    {
        $connection = static::ENTITY_CLASS::getDatabase()->connectionIndex;
        if (!$this->isRelated() || Database::runtimeTransactionLevel($connection) === null) {
            throw new DatabaseException('A merge copy must be completed inside its birth transaction');
        }
        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            $this->getIdString(),
            $this->touchedSetKeys(...),
            TruthSourceOperation::Add,
        );
        Database::sql(
            'UPDATE ' . static::ENTITY_CLASS::getSqlTableName() . ' SET `payload_json` = ?'
            . ' WHERE `assignment_id` = ? AND `payload_json` IS NULL',
            [$copy->toJson(), $this->entity->assignment_id],
            index: $connection,
        );
        if (Database::affectedRows($connection) !== 1) {
            throw new DatabaseException('An immutable merge copy cannot be replaced');
        }
    }

    /**
     * @internal The guarded View reader is the application boundary
     * @return AccountMergeHandoverCopy Fresh private payload, never retained in the ORM
     * @throws HilosException When storage or the saved copy refuses the read
     */
    public function readPayload(): AccountMergeHandoverCopy
    {
        $row = Database::sql(
            'SELECT `payload_json` FROM ' . static::ENTITY_CLASS::getSqlTableName() . ' WHERE `assignment_id` = ?',
            [$this->entity->assignment_id],
            index: static::ENTITY_CLASS::getDatabase()->connectionIndex,
        )->firstRow();
        if (!is_string($row[EntityAccountMergeCopy::payload_json] ?? null)) {
            throw new DatabaseException('The merge copy has no complete stored payload');
        }

        return AccountMergeHandoverCopy::fromJson($row[EntityAccountMergeCopy::payload_json]);
    }

    /** @return string Assignment primary key, independent of its object-field spelling */
    public function getIdString(): string
    {
        return (string)$this->entity->assignment_id;
    }

    /** @return list<string> Primary key in the object serialization */
    public function getPrimaryKeyArrayKeys(): array
    {
        return [self::assignmentId];
    }

    /** @return array<string, mixed> Stored fields with object keys */
    public function toArray(): array
    {
        return [
            self::assignmentId => $this->entity->assignment_id,
            self::ownerAgentId => $this->entity->owner_agent_id,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
