<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Auth\AccountOperation\AccountMergeTransfer;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\AccountMergeFenceSnapshot;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\AccountMergeFence as EntityAccountMergeFence;
use Hilos\Database\Object\Collection\AccountMergeFences as ObjectAccountMergeFences;
use Hilos\HilosException;

/**
 * @extends Object_<EntityAccountMergeFence>
 * @property-read string $collectionKey
 * @property-read int $rowId
 * @property-read int $operationId
 * @property-read int $assignmentId
 * @property-read int $ownerUserId
 * @property-read int $revision
 * @property-read bool $deleted
 */
class AccountMergeFence extends Object_
{
    public const string ENTITY_CLASS = EntityAccountMergeFence::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountMergeFences::class;
    public const string collectionKey = 'collectionKey';
    public const string rowId = 'rowId';
    public const string operationId = 'operationId';
    public const string assignmentId = 'assignmentId';
    public const string ownerUserId = 'ownerUserId';
    public const string revision = 'revision';
    public const string deleted = 'deleted';

    /** This process's applied revision; durable metadata remains independently checked. */
    private int $appliedRevision = 0;

    /** @return int Last revision applied by this ORM context, including a retained tombstone */
    public function appliedRevision(): int
    {
        return $this->appliedRevision;
    }

    /**
     * @param int $revision Successfully applied revision
     * @throws DatabaseException When the row connection cannot be resolved
     */
    public function markApplied(int $revision): void
    {
        if ($revision <= $this->appliedRevision) {
            return;
        }
        $before = $this->appliedRevision;
        Database::onRollback(function () use ($before): void {
            $this->appliedRevision = $before;
        }, index: static::ENTITY_CLASS::getDatabase()->connectionIndex);
        $this->appliedRevision = $revision;
    }

    /**
     * Refreshes the metadata mirror in place without announcing a read or rewinding applied progress.
     *
     * @param AccountMergeFenceSnapshot $snapshot Current durable metadata
     * @throws DatabaseException When the snapshot names a different fence or its connection cannot be resolved
     */
    public function refreshSnapshot(AccountMergeFenceSnapshot $snapshot): void
    {
        if ($snapshot->collectionKey !== $this->entity->collection_key || $snapshot->rowId !== $this->entity->row_id) {
            throw new DatabaseException('A merge fence snapshot must retain its primary key.');
        }
        $before = $this->entitySync->toArray();
        Database::onRollback(fn () => $this->restoreSaved($before, related: true),
            index: static::ENTITY_CLASS::getDatabase()->connectionIndex);
        $this->entity->operation_id = $snapshot->operationId;
        $this->entity->assignment_id = $snapshot->assignmentId;
        $this->entity->owner_user_id = $snapshot->ownerUserId;
        $this->entity->revision = $snapshot->revision;
        $this->entity->deleted = $snapshot->deleted;
        $this->syncRelated();
    }

    /**
     * @param string $property Metadata field
     * @return mixed Stored scalar
     * @throws DatabaseException When the field is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::collectionKey => $this->entity->collection_key,
            self::rowId => $this->entity->row_id,
            self::operationId => $this->entity->operation_id,
            self::assignmentId => $this->entity->assignment_id,
            self::ownerUserId => $this->entity->owner_user_id,
            self::revision => $this->entity->revision,
            self::deleted => $this->entity->deleted,
            default => parent::__get($property),
        };
    }

    /** @return array<string, mixed> Nonsecret object fields */
    public function toArray(): array
    {
        return [
            self::collectionKey => $this->entity->collection_key,
            self::rowId => $this->entity->row_id,
            self::operationId => $this->entity->operation_id,
            self::assignmentId => $this->entity->assignment_id,
            self::ownerUserId => $this->entity->owner_user_id,
            self::revision => $this->entity->revision,
            self::deleted => $this->entity->deleted,
        ];
    }

    /** @return string Exact composite storage key, independent of object-field spelling */
    public function getIdString(): string
    {
        return $this->entity->collection_key . ':' . $this->entity->row_id;
    }

    /** @return list<string> Composite primary fields in object serialization */
    public function getPrimaryKeyArrayKeys(): array
    {
        return [self::collectionKey, self::rowId];
    }

    /** @return AccountMergeFenceSnapshot This object's metadata, without a fresh storage read */
    public function snapshot(): AccountMergeFenceSnapshot
    {
        return new AccountMergeFenceSnapshot(
            $this->entity->collection_key,
            $this->entity->row_id,
            $this->entity->operation_id,
            $this->entity->assignment_id,
            $this->entity->owner_user_id,
            $this->entity->revision,
            $this->entity->deleted,
        );
    }

    /**
     * @internal Only the collection birth door creates metadata
     * @param AccountMergeTransfer $transfer Active right for the captured source row
     * @throws HilosException When the transaction, guard, insert or announcement refuses birth
     */
    public function birthForMerge(AccountMergeTransfer $transfer): void
    {
        $this->requireTransaction();
        if ($this->isRelated() || $this->entity->revision !== 1 || $this->entity->deleted
            || $this->entity->owner_user_id !== $transfer->survivorUserId
            || $this->entity->operation_id !== $transfer->operationId || $this->entity->assignment_id !== $transfer->assignmentId) {
            throw new DatabaseException('A merge fence birth requires exact initial target metadata.');
        }
        DbWriteGuard::guardMergeFenceTransfer($this, $transfer, true);
        $before = $this->entity->toArray();
        $this->entity->save();
        Database::onRollback(fn () => $this->restoreSaved($before, related: false), index: static::ENTITY_CLASS::getDatabase()->connectionIndex);
        $this->syncRelated();
        $this->broadcastDbSyncAfterSync(true, $this->entity->toArray(), []);
    }

    /**
     * @param bool $delete Whether this mutation deletes the business row
     * @return AccountMergeFenceSnapshot Next revision under the unchanged canonical owner
     * @throws HilosException When the transaction, current owner, guard or CAS refuses the write
     */
    public function advanceForWrite(bool $delete = false): AccountMergeFenceSnapshot
    {
        $this->guardOrdinaryWrite();

        return $this->compareAndAdvance(null, $delete);
    }

    /**
     * The business scope asks this before its SQL, and the advance asks it again before CAS.
     *
     * @throws HilosException When the transaction, clean metadata or current writer refuses an ordinary mutation
     */
    public function guardOrdinaryWrite(): void
    {
        $this->requireTransaction();
        if (!$this->isRelated() || $this->hasChanges() || $this->entity->deleted) {
            throw new DatabaseException('A merge fence advance requires clean stored metadata.');
        }
        DbWriteGuard::guardItemWrite(static::getCollectionKey(), $this->getIdString(), $this->touchedSetKeys(...), TruthSourceOperation::Update);
    }

    /**
     * @param AccountMergeTransfer $transfer Active source-to-target capability
     * @return AccountMergeFenceSnapshot Transferred metadata at its next revision
     * @throws HilosException When the transaction, capture, source grant, membership or CAS refuses the write
     */
    public function giveForMerge(AccountMergeTransfer $transfer): AccountMergeFenceSnapshot
    {
        $this->requireTransaction();
        if (!$this->isRelated() || $this->hasChanges() || $this->entity->deleted) {
            throw new DatabaseException('A merge fence give requires clean live stored metadata.');
        }
        DbWriteGuard::guardMergeFenceTransfer($this, $transfer, false);

        return $this->compareAndAdvance($transfer);
    }

    /**
     * @param ?AccountMergeTransfer $transfer Transfer coordinates, or null to keep them unchanged
     * @param bool $delete Whether to retain a business tombstone
     * @return AccountMergeFenceSnapshot Metadata written by this CAS
     * @throws HilosException When storage, the revision comparison or synchronization refuses the write
     */
    private function compareAndAdvance(?AccountMergeTransfer $transfer, bool $delete = false): AccountMergeFenceSnapshot
    {
        $before = $this->entitySync->toArray();
        if ($this->entity->revision === PHP_INT_MAX) {
            throw new DatabaseException('The merge fence revision cannot advance.');
        }
        $index = static::ENTITY_CLASS::getDatabase()->connectionIndex;
        $operationId = $transfer?->operationId ?? $this->entity->operation_id;
        $assignmentId = $transfer?->assignmentId ?? $this->entity->assignment_id;
        $ownerUserId = $transfer?->survivorUserId ?? $this->entity->owner_user_id;
        Database::sql(
            'UPDATE ' . static::ENTITY_CLASS::getSqlTableName()
            . ' SET `operation_id` = ?, `assignment_id` = ?, `owner_user_id` = ?, `deleted` = ?, `revision` = `revision` + 1'
            . ' WHERE `collection_key` = ? AND `row_id` = ? AND `owner_user_id` = ? AND `revision` = ? AND `deleted` = 0',
            [$operationId, $assignmentId, $ownerUserId, $delete ? 1 : 0, $this->entity->collection_key, $this->entity->row_id,
                $this->entity->owner_user_id, $this->entity->revision],
            index: $index,
        );
        if (Database::affectedRows($index) !== 1) {
            throw new DatabaseException('The merge fence owner or revision changed.');
        }
        $this->entity->operation_id = $operationId;
        $this->entity->assignment_id = $assignmentId;
        $this->entity->owner_user_id = $ownerUserId;
        $this->entity->deleted = $delete;
        ++$this->entity->revision;
        $after = $this->entity->toArray();
        Database::onRollback(function () use ($before, $after): void {
            $this->restoreSaved($before, related: true);
            SourceChangeBus::publishToMirrors(SourceChange::dbUpdated(
                static::getCollectionKey(), $this->getIdString(), $before,
                ExecutionContext::currentAcceptKey(), ExecutionContext::currentRequestId(), previous: $after,
            ));
        }, index: $index);
        $this->syncRelated();
        $this->broadcastDbSyncAfterSync(false, $after, $before);

        return $this->snapshot();
    }

    /**
     * @throws HilosException When the caller has no PRIMARY row transaction or cannot write it
     */
    private function requireTransaction(): void
    {
        $index = static::ENTITY_CLASS::getDatabase()->connectionIndex;
        Database::assertCanWrite($index);
        if (Database::runtimeTransactionLevel($index) === null) {
            throw new DatabaseException('Merge fence writes must join their business row transaction.');
        }
    }
}
