<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Sync\DTO\DbSyncAdmissionData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Collection\AccountMergeFences as ObjectAccountMergeFences;
use Hilos\Database\Object\Collection\AccountOperationResults as ObjectAccountOperationResults;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Throwable;

/** Fresh durable admission before any business lookup, mutation or consumer effect. */
final class DbSyncAdmission
{
    /**
     * @param string $collectionKey Sync collection
     * @return bool Whether the master must transport this collection without applying or reading it
     */
    public static function transportOnly(string $collectionKey): bool
    {
        return $collectionKey === HilosDbContext::accountMergeFences
            || in_array($collectionKey, ObjectAccountMergeFences::BUSINESS_COLLECTIONS, true);
    }

    /**
     * Ordinary writes remain admissible after an operation has closed; only the last transfer coordinates matter here.
     * All failures remain refusals, with a reason that never includes a payload or database exception text.
     *
     * @param DbSyncSignalDataInterface $data Incoming business fact
     * @return DbSyncApplyResult Fresh admission verdict and the process-local progress carrier
     */
    public static function check(DbSyncSignalDataInterface $data): DbSyncApplyResult
    {
        if (!in_array($data->collectionKey, ObjectAccountMergeFences::BUSINESS_COLLECTIONS, true)) {
            return new DbSyncApplyResult($data->admission === null);
        }
        try {
            $fences = Hilos::$db->mountedObjectCollection(HilosDbContext::accountMergeFences);
            if (!$fences instanceof ObjectAccountMergeFences) {
                throw new DatabaseException('DB sync admission metadata is not mounted.');
            }
            $current = $fences->fresh($data->collectionKey . ':' . $data->idString);
            $stamp = $data->admission;
            if ($current === null || $stamp === null) {
                return new DbSyncApplyResult($current === null && $stamp === null);
            }
            $stamp->validate($data->collectionKey, $data->idString, $data instanceof DbSyncDeletedSignalData,
                row: $data->row, previous: $data instanceof DbSyncUpdatedSignalData ? $data->previous : null);
            if ($stamp->operationId !== $current->operationId || $stamp->assignmentId !== $current->assignmentId
                || $stamp->ownerUserId !== $current->ownerUserId || $stamp->revision !== $current->revision
                || ($data instanceof DbSyncDeletedSignalData) !== $current->deleted || !self::matchesWriter($data, $stamp)) {
                return new DbSyncApplyResult(false);
            }
            $fence = $fences->rememberSnapshot($current);
            if ($fence->appliedRevision() >= $stamp->revision) {
                return new DbSyncApplyResult(false);
            }

            return new DbSyncApplyResult(true, fence: $fence);
        } catch (Throwable) {
            Logger::warning('DB sync admission refused: current metadata or frame validation unavailable.');

            return new DbSyncApplyResult(false);
        }
    }

    /**
     * The source may emit its committed give, while subsequent edits are emitted by the canonical owner.
     * A saved give fact supplies the source's exact original revision without reading its secret copy.
     *
     * @param DbSyncSignalDataInterface $data Incoming fact
     * @param DbSyncAdmissionData $stamp Current validated stamp
     * @return bool Whether the writer is the current owner or the source of this exact give revision
     */
    private static function matchesWriter(DbSyncSignalDataInterface $data, DbSyncAdmissionData $stamp): bool
    {
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$stamp->ownerUserId);
        if ($data->writerAgentId === $owner->agentId()) {
            return true;
        }
        $assignments = Hilos::$db->mountedObjectCollection(HilosDbContext::accountOperationAssignments);
        $assignment = $assignments?->get($stamp->assignmentId);
        if ($assignment === null || $assignment->operationId !== $stamp->operationId || $data instanceof DbSyncDeletedSignalData) {
            return false;
        }
        $source = new AccountOperationOwnerAddress($assignment->ownerType, $assignment->ownerIndex);
        if ($data->writerAgentId !== $source->agentId()) {
            return false;
        }
        $results = Hilos::$db->mountedObjectCollection(HilosDbContext::accountOperationResults);
        if (!$results instanceof ObjectAccountOperationResults) {
            return false;
        }
        $result = $results->byAssignment($stamp->assignmentId);
        if ($result === null) {
            return false;
        }
        $manifest = AccountMergeGiveResult::of(AccountOperationResultPayload::fromJson($result->payloadJson))->admission;
        foreach ($manifest->{$data->collectionKey} as $revision) {
            if ($revision->id === (int)$data->idString && $revision->revision === $stamp->revision) {
                return true;
            }
        }

        return false;
    }
}
