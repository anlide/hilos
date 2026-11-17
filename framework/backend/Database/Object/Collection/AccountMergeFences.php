<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\AccountOperation\AccountMergeReceiving;
use Hilos\Auth\AccountOperation\AccountMergeTransfer;
use Hilos\Auth\AccountOperation\Exception\AccountMergeReceiveWaitsException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Database\DatabaseException;
use Hilos\Database\AccountMergeFenceSnapshot;
use Hilos\Database\AccountMergeReceiptSnapshot;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Collection\AccountMergeFences as EntityAccountMergeFences;
use Hilos\Database\Entity\Item\AccountMergeFence as EntityAccountMergeFence;
use Hilos\Database\Object\Item\AccountMergeFence as ObjectAccountMergeFence;
use Hilos\Database\Object\Objects;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;

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
     * The startup read is confined to current own mapped rows; lazy loaders preserve stale held Objects.
     *
     * @param int $giveAssignmentId Exact original source assignment
     * @param int $survivorUserId Canonical executing survivor
     * @return AccountMergeReceiptSnapshot Attempt-local current rows bracketed by fresh transfer metadata
     * @throws HilosException When authorization, transaction state, metadata or mapped storage refuses the read
     * @throws JsonException When a saved preparation cannot be decoded
     */
    public function receiptSnapshot(int $giveAssignmentId, int $survivorUserId): AccountMergeReceiptSnapshot
    {
        if (Database::hasOpenTransactions()) {
            throw new InvalidFormatException('A merge receipt snapshot requires autocommit current reads.');
        }
        $give = AccountMergeReceiving::receiptManifest($giveAssignmentId, $survivorUserId);
        $operationId = Hilos::$db->accountOperationAssignments[$giveAssignmentId]->operationId;
        $fences = [];
        foreach (self::BUSINESS_COLLECTIONS as $key) {
            foreach ($give->admission->{$key} as $stamp) {
                $fenceKey = $key . ':' . $stamp->id;
                $current = $this->fresh($fenceKey);
                if ($current === null || $current->operationId !== $operationId || $current->assignmentId !== $giveAssignmentId
                    || $current->ownerUserId !== $survivorUserId || $current->revision < $stamp->revision) {
                    throw new AccountMergeReceiveWaitsException();
                }
                $fences[$fenceKey] = $current;
            }
        }
        $rows = [];
        foreach (AccountMergeReceiptSnapshot::COLLECTIONS as $key) {
            $collection = Hilos::$db->mountedObjectCollection($key);
            if ($collection === null || !$collection->cachesRows()) {
                throw new InvalidFormatException('The merge receipt requires its mounted row collections.');
            }
            $entityClass = $collection::OBJECT_CLASS::ENTITY_CLASS;
            $columns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $entityClass::_columns));
            $rows[$key] = [];
            foreach (Database::sql('SELECT ' . $columns . ' FROM ' . $entityClass::getSqlTableName() . ' WHERE `user_id` = ?',
                [$survivorUserId], index: $entityClass::getDatabase()->connectionIndex)->rows() as $row) {
                $mapped = $entityClass::fromRow($row)->toArray();
                $rows[$key][$mapped[$entityClass::_primary]] = $mapped;
            }
        }
        foreach ($fences as $key => $expected) {
            if ($this->fresh($key) != $expected
                || $expected->deleted === isset($rows[$expected->collectionKey][$expected->rowId])) {
                throw new AccountMergeReceiveWaitsException();
            }
        }

        return new AccountMergeReceiptSnapshot($operationId, $giveAssignmentId, $survivorUserId, $rows, $fences);
    }

    /**
     * The participant holds all expected metadata locks before the first memory effect.
     *
     * @param AccountMergeReceiptSnapshot $snapshot Current own startup rows, never the original birth copy
     * @throws HilosException When the transaction, coordinates, current metadata or hydration refuses resume
     * @throws JsonException When a saved preparation cannot be decoded
     */
    public function hydrateReceiptSnapshot(AccountMergeReceiptSnapshot $snapshot): void
    {
        if (Database::runtimeTransactionLevel(static::entityClass()::getDatabase()->connectionIndex) === null) {
            throw new InvalidFormatException('Merge receipt hydration requires its participant transaction.');
        }
        $give = AccountMergeReceiving::receiptManifest($snapshot->giveAssignmentId, $snapshot->survivorUserId);
        if (Hilos::$db->accountOperationAssignments[$snapshot->giveAssignmentId]->operationId !== $snapshot->operationId) {
            throw new InvalidFormatException('The merge receipt snapshot belongs to another operation.');
        }
        foreach (self::BUSINESS_COLLECTIONS as $key) {
            foreach ($give->admission->{$key} as $stamp) {
                $fenceKey = $key . ':' . $stamp->id;
                $expected = $snapshot->fences[$fenceKey] ?? null;
                if ($expected === null || $expected->operationId !== $snapshot->operationId
                    || $expected->assignmentId !== $snapshot->giveAssignmentId || $expected->ownerUserId !== $snapshot->survivorUserId
                    || $expected->revision < $stamp->revision || $this->fresh($fenceKey) != $expected) {
                    throw new AccountMergeReceiveWaitsException();
                }
            }
        }
        SourceChangeBus::whileApplyingRemote(function () use ($snapshot): void {
            foreach (AccountMergeReceiptSnapshot::COLLECTIONS as $key) {
                $collection = Hilos::$db->mountedObjectCollection($key);
                $objectClass = $collection::OBJECT_CLASS;
                $entityClass = $objectClass::ENTITY_CLASS;
                foreach ($collection->objects as $id => $object) {
                    $current = $snapshot->fences[$key . ':' . $id] ?? null;
                    if (($object->userId === $snapshot->survivorUserId || $current?->deleted === true)
                        && !isset($snapshot->rows[$key][$id])) {
                        if ($object->hasChanges()) {
                            throw new InvalidFormatException('Merge receipt hydration refuses unsaved own edits.');
                        }
                        unset($collection[$id]);
                    }
                }
                foreach ($snapshot->rows[$key] as $id => $mapped) {
                    if (isset($collection[$id])) {
                        if ($collection[$id]->hasChanges()) {
                            throw new InvalidFormatException('Merge receipt hydration refuses unsaved mapped edits.');
                        }
                        $collection[$id]->applyDbSyncEntityUpdate($mapped, invalidateUnmapped: true);
                    } else {
                        $object = $objectClass::fromEntity($entityClass::fromRow($mapped));
                        $object->applyDbSyncUnmappedUpdate();
                        $collection[$id] = $object;
                    }
                }
            }
            foreach ($snapshot->fences as $expected) {
                $this->rememberSnapshot($expected)->markApplied($expected->revision);
            }
        });
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
