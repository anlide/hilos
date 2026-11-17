<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\AccountOperation\AccountMergeTransfer;
use Hilos\Database\DatabaseException;
use Hilos\Database\AccountMergeFenceSnapshot;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Collection\AccountMergeFences as EntityAccountMergeFences;
use Hilos\Database\Entity\Item\AccountMergeFence as EntityAccountMergeFence;
use Hilos\Database\Object\Item\AccountMergeFence as ObjectAccountMergeFence;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;

/**
 * Composite offsets are collectionKey:decimalRowId; only successful key loads are cached.
 *
 * @extends Objects<ObjectAccountMergeFence>
 * @method ObjectAccountMergeFence|null first()
 * @method ObjectAccountMergeFence|null last()
 * @method ObjectAccountMergeFence|null get(int|string $key)
 * @method ObjectAccountMergeFence|null offsetGet(mixed $offset)
 */
class AccountMergeFences extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountMergeFence::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountMergeFences::class;
    public const string COLLECTION_KEY = HilosDbContext::accountMergeFences;
    public const array BUSINESS_COLLECTIONS = [
        HilosDbContext::identities, HilosDbContext::passkeyCredentials, HilosDbContext::secondFactors, HilosDbContext::secondFactorBackupCodes,
    ];

    /**
     * Bypasses both stale and negative mirrors without replacing Objects or their local progress.
     *
     * @param string $key Exact composite key
     * @return ?AccountMergeFenceSnapshot Current durable metadata
     * @throws HilosException When the key or storage refuses the read
     */
    public function fresh(string $key): ?AccountMergeFenceSnapshot
    {
        [$collectionKey, $rowId] = self::coordinates($key);
        $index = static::entityClass()::getDatabase()->connectionIndex;
        $lock = Database::runtimeTransactionLevel($index) === null ? '' : ' LOCK IN SHARE MODE';
        $row = Database::sql(
            'SELECT * FROM ' . static::entityClass()::getSqlTableName() . ' WHERE `collection_key` = ? AND `row_id` = ?' . $lock,
            [$collectionKey, $rowId], index: $index,
        )->firstRow();
        if ($row === null) {
            return null;
        }
        $entity = static::entityClass()::fromRow($row);

        return new AccountMergeFenceSnapshot($entity->collection_key, $entity->row_id, $entity->operation_id,
            $entity->assignment_id, $entity->owner_user_id, $entity->revision, $entity->deleted);
    }

    /**
     * Hydrates only metadata already read by fresh(), keeping existing Object and View identities.
     *
     * @param AccountMergeFenceSnapshot $snapshot Current durable coordinates
     * @return ObjectAccountMergeFence Process-local metadata and applied progress
     * @throws HilosException When the key or metadata mirror refuses hydration
     */
    public function rememberSnapshot(AccountMergeFenceSnapshot $snapshot): ObjectAccountMergeFence
    {
        $key = $snapshot->collectionKey . ':' . $snapshot->rowId;
        self::coordinates($key);
        if (isset($this[$key])) {
            $object = $this[$key];
            $object->refreshSnapshot($snapshot);

            return $object;
        }
        $entity = static::entityClass()::fromRow([
            EntityAccountMergeFence::collection_key => $snapshot->collectionKey,
            EntityAccountMergeFence::row_id => $snapshot->rowId,
            EntityAccountMergeFence::operation_id => $snapshot->operationId,
            EntityAccountMergeFence::assignment_id => $snapshot->assignmentId,
            EntityAccountMergeFence::owner_user_id => $snapshot->ownerUserId,
            EntityAccountMergeFence::revision => $snapshot->revision,
            EntityAccountMergeFence::deleted => $snapshot->deleted,
        ]);
        $object = static::OBJECT_CLASS::fromEntity($entity);
        $this->hydrate($key, $object);

        return $object;
    }

    /**
     * @param AccountMergeTransfer $transfer Active source give capability
     * @param string $collectionKey Captured business collection
     * @param int $rowId Captured positive business id
     * @return ObjectAccountMergeFence New target-owned metadata
     * @throws HilosException When the key, transaction, capture, source Add or insert refuses birth
     */
    public function birthForMerge(AccountMergeTransfer $transfer, string $collectionKey, int $rowId): ObjectAccountMergeFence
    {
        self::coordinates($collectionKey . ':' . $rowId);
        $entity = static::entityClass()::getEmpty();
        $entity->collection_key = $collectionKey;
        $entity->row_id = $rowId;
        $entity->operation_id = $transfer->operationId;
        $entity->assignment_id = $transfer->assignmentId;
        $entity->owner_user_id = $transfer->survivorUserId;
        $entity->revision = 1;
        $entity->deleted = false;
        $object = static::OBJECT_CLASS::fromEntity($entity);
        $object->birthForMerge($transfer);
        $this[$object->getIdString()] = $object;

        return $object;
    }

    /**
     * @param int|string|null $key Composite key, or null
     * @return ?ObjectAccountMergeFence Exact stored row
     * @throws DatabaseException When the key or storage refuses the lookup
     */
    protected function lazyLoadObject(int|string|null $key): ?ObjectAccountMergeFence
    {
        if ($key === null) {
            return null;
        }
        [$collectionKey, $rowId] = self::coordinates((string)$key);
        $rows = static::entityClass()::get( // fresh-sql: the cold composite offset must match both primary columns.
            [EntityAccountMergeFence::collection_key => $collectionKey, EntityAccountMergeFence::row_id => $rowId],
        );
        $entity = $rows->first();

        return $entity === null ? null : static::OBJECT_CLASS::fromEntity($entity);
    }

    /**
     * @param string $key Canonical composite offset
     * @return array{string, int} Validated collection and row id
     * @throws DatabaseException When the collection or canonical positive decimal id is invalid
     */
    private static function coordinates(string $key): array
    {
        $parts = explode(':', $key);
        if (count($parts) !== 2 || !in_array($parts[0], self::BUSINESS_COLLECTIONS, true)
            || !ctype_digit($parts[1]) || (int)$parts[1] <= 0 || (string)(int)$parts[1] !== $parts[1]) {
            throw new DatabaseException('A merge fence requires a supported collection and positive canonical row id.');
        }

        return [$parts[0], (int)$parts[1]];
    }
}
