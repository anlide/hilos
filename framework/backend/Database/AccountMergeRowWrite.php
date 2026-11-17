<?php

declare(strict_types=1);

namespace Hilos\Database;

use Closure;
use Hilos\Auth\AccountOperation\AccountMergeTransfer;
use Hilos\Core\Sync\DTO\DbSyncAdmissionData;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Object\Collection\AccountMergeFences as ObjectAccountMergeFences;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos;
use Hilos\HilosException;
use Throwable;

/** One synchronous business write and its fence, without ambient transfer rights or retained state. */
final class AccountMergeRowWrite
{
    /**
     * A targeted SQL write must not announce or mark unrelated unsaved mapped edits as persisted.
     *
     * @param Object_ $row Saved business Object
     * @param Closure(?AccountMergeFenceSnapshot): mixed $write Targeted write and its baseline update
     * @return mixed Original proof outcome or write result
     * @throws DatabaseException When unsaved mapped edits or storage prevent the targeted write
     * @throws WriteNotAllowedException When the current fence refuses this writer
     */
    public static function raw(Object_ $row, Closure $write): mixed
    {
        if ($row->isRelated() && $row->hasChanges()) {
            throw new DatabaseException('A targeted row write requires its saved mapped state.');
        }

        return self::run($row, false, $write);
    }

    /**
     * @param Object_ $row Business Object being written
     * @param bool $delete Whether the mutation removes the business row
     * @param Closure(?AccountMergeFenceSnapshot): mixed $write Physical write and its final announcement
     * @return mixed Callback result after this scope's commit, or inside the caller's transaction
     * @throws DatabaseException When metadata or the row transaction refuses the write
     * @throws WriteNotAllowedException When the row no longer belongs to its fenced owner
     */
    public static function run(Object_ $row, bool $delete, Closure $write): mixed
    {
        if ($row::OBJECT_COLLECTION_CLASS === ''
            || !in_array($row::OBJECT_COLLECTION_CLASS::COLLECTION_KEY, ObjectAccountMergeFences::BUSINESS_COLLECTIONS, true)
            || !$row->isRelated()) {
            return $write(null);
        }

        return self::transaction($row, static function () use ($row, $write): mixed {
            $snapshot = self::current($row);
            if ($snapshot !== null) {
                if ($snapshot->deleted || $row->storedSetTop() !== (string)$snapshot->ownerUserId) {
                    throw new WriteNotAllowedException('The business row no longer belongs to its current merge fence owner.');
                }
                try {
                    $fences = Hilos::$db->mountedObjectCollection(ObjectAccountMergeFences::COLLECTION_KEY);
                    $fences->rememberSnapshot($snapshot)->guardOrdinaryWrite();
                } catch (WriteNotAllowedException $failure) {
                    throw $failure;
                } catch (HilosException $failure) {
                    throw new DatabaseException('Merge fence write admission refused the mutation.', previous: $failure);
                }
            }

            return $write($snapshot);
        });
    }

    /**
     * @param Object_ $row Captured business row
     * @param AccountMergeTransfer $transfer Active source-to-target right
     * @param Closure(AccountMergeFenceSnapshot): mixed $write Physical transfer and its final announcement
     * @return mixed Transfer callback result
     * @throws HilosException When the transfer guard, stamp or write refuses the mutation
     */
    public static function transfer(Object_ $row, AccountMergeTransfer $transfer, Closure $write): mixed
    {
        return self::transaction($row, static function () use ($row, $transfer, $write): mixed {
            DbWriteGuard::guardMergeTransfer($row::OBJECT_COLLECTION_CLASS::COLLECTION_KEY, $row, $transfer);
            $transfer->stampRow($row::OBJECT_COLLECTION_CLASS::COLLECTION_KEY, $row);
            $snapshot = self::current($row);
            if ($snapshot === null) {
                throw new DatabaseException('A completed transfer stamp requires its fence.');
            }

            return $write($snapshot);
        });
    }

    /**
     * Called only after an actual physical mutation; a failed proof or unchanged diff never reaches this door.
     *
     * @param Object_ $row Successfully written business row
     * @param ?AccountMergeFenceSnapshot $snapshot Current coordinates read before the write
     * @param bool $delete Whether the row was removed
     * @param bool $transfer Whether the transfer already advanced the fence
     * @return ?DbSyncAdmissionData Committed-with-row coordinates for this announcement
     * @throws DatabaseException When metadata or the mapped snapshot refuses completion
     * @throws WriteNotAllowedException When the current writer cannot advance the fence
     */
    public static function complete(
        Object_ $row,
        ?AccountMergeFenceSnapshot $snapshot,
        bool $delete = false,
        bool $transfer = false,
    ): ?DbSyncAdmissionData {
        if ($snapshot === null) {
            return null;
        }
        try {
            $fences = Hilos::$db->mountedObjectCollection(ObjectAccountMergeFences::COLLECTION_KEY);
            if (!$fences instanceof ObjectAccountMergeFences) {
                throw new DatabaseException('Merge fence metadata is not mounted.');
            }
            $fence = $fences->rememberSnapshot($snapshot);
            if (!$transfer) {
                $snapshot = $fence->advanceForWrite($delete);
            }
            $fence->markApplied($snapshot->revision);
            $mapped = null;
            if (!$delete) {
                $mapped = [];
                foreach ($row::ENTITY_CLASS::_columns as $column) {
                    $mapped[$column] = $row->valueForColumn($column);
                }
            }
            $admission = new DbSyncAdmissionData(
                $snapshot->operationId, $snapshot->assignmentId, $snapshot->ownerUserId, $snapshot->revision, $mapped,
            );
            $admission->validate($row::OBJECT_COLLECTION_CLASS::COLLECTION_KEY, (string)$snapshot->rowId, $delete);
        } catch (WriteNotAllowedException $failure) {
            throw $failure;
        } catch (HilosException $failure) {
            throw new DatabaseException('Merge fence completion refused the row mutation.', previous: $failure);
        }

        return $admission;
    }

    /**
     * @param Object_ $row Existing business row
     * @return ?AccountMergeFenceSnapshot Independently read durable coordinates
     * @throws DatabaseException When the metadata binding or current read fails
     */
    private static function current(Object_ $row): ?AccountMergeFenceSnapshot
    {
        try {
            $fences = Hilos::$db->mountedObjectCollection(ObjectAccountMergeFences::COLLECTION_KEY);
            if (!$fences instanceof ObjectAccountMergeFences) {
                throw new DatabaseException('Merge fence metadata is not mounted.');
            }

            return $fences->fresh($row::OBJECT_COLLECTION_CLASS::COLLECTION_KEY . ':' . $row->getIdString());
        } catch (HilosException $failure) {
            throw new DatabaseException('Merge fence current metadata could not be read.', previous: $failure);
        }
    }

    /**
     * @param Object_ $row Row whose connection owns the transaction
     * @param Closure(): mixed $write Synchronous work on that connection
     * @return mixed Callback result after an owned commit
     */
    private static function transaction(Object_ $row, Closure $write): mixed
    {
        $index = $row::ENTITY_CLASS::getDatabase()->connectionIndex;
        if (Database::runtimeTransactionLevel($index) !== null) {
            return $write();
        }
        try {
            Database::transactionStart(index: $index);
        } catch (NestedTransactionRefusedException $failure) {
            throw new DatabaseException('The row write transaction could not start.', previous: $failure);
        }
        try {
            $result = $write();
            Database::transactionCommit(index: $index);
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent(index: $index);
            throw $failure;
        }

        return $result;
    }
}
