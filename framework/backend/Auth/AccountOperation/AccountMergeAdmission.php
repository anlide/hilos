<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Closure;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Sync\DTO\DbSyncAdmissionData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Collection\AccountMergeFences as ObjectAccountMergeFences;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;
use JsonException;
use Throwable;

/**
 * Admits the source's committed batch at the canonical survivor, using only mapped snapshots.
 * SCAFFOLD: called by the merge coordinator (HIL-2000); no production caller until the merge switch (HIL-1999).
 * HIL-1994 owns the separate receive receipt.
 */
final class AccountMergeAdmission
{
    /**
     * Fresh metadata and process-local progress are shared with DB_SYNC, independently of secret copy retention.
     * A caller's transaction is joined; an application failure in that scope must roll back with its caller.
     *
     * @param int $survivorUserId Person represented by the executing owner
     * @param AccountMergeHandover $handover Immutable source snapshots and original transfer revisions
     * @return AccountMergeAdmissionResult Whole-batch verdict, never a receive receipt
     * @throws Throwable When hydration fails inside the caller's existing transaction
     */
    public static function apply(int $survivorUserId, AccountMergeHandover $handover): AccountMergeAdmissionResult
    {
        if (Database::runtimeTransactionLevel(Database::getDatabase(DatabaseNames::PRIMARY)->connectionIndex) !== null) {
            return self::admit($survivorUserId, $handover);
        }
        try {
            return Database::transaction(DatabaseNames::PRIMARY,
                static fn (): AccountMergeAdmissionResult => self::admit($survivorUserId, $handover));
        } catch (Throwable) {
            return AccountMergeAdmissionResult::Rejected;
        }
    }

    /**
     * Preflight builds only short-lived closures over mapped data; no secret-bearing row is retained by an effect.
     *
     * @param int $survivorUserId Executing survivor
     * @param AccountMergeHandover $handover Saved source batch
     * @return AccountMergeAdmissionResult Admission after complete validation, or a refusal without business effects
     * @throws HilosException When an admitted memory mutation or announcement fails
     */
    private static function admit(int $survivorUserId, AccountMergeHandover $handover): AccountMergeAdmissionResult
    {
        try {
            self::guard($survivorUserId, $handover);
            $fences = Hilos::$db->mountedObjectCollection(HilosDbContext::accountMergeFences);
            if (!$fences instanceof ObjectAccountMergeFences) {
                return AccountMergeAdmissionResult::Rejected;
            }
            /** @var list<Closure(): void> $effects In-order memory work after every row passes preflight */
            $effects = [];
            $pending = false;
            $empty = true;
            foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
                $collection = Hilos::$db->mountedObjectCollection($key);
                if ($collection === null || !$collection->cachesRows()) {
                    return AccountMergeAdmissionResult::Rejected;
                }
                $objectClass = $collection::OBJECT_CLASS;
                $entityClass = $objectClass::ENTITY_CLASS;
                foreach ($handover->copy->{$key} as $index => $row) {
                    $empty = false;
                    $revision = $handover->admission->{$key}[$index]->revision;
                    $mapped = self::mapped($row, $entityClass);
                    (new DbSyncAdmissionData($handover->copy->operationId, $handover->copy->assignmentId,
                        $survivorUserId, $revision, $mapped))->validate($key, (string)$row->id, false, $mapped);
                    $fenceKey = $key . ':' . $row->id;
                    $current = $fences->fresh($fenceKey);
                    if ($current === null || $current->operationId !== $handover->copy->operationId
                        || $current->assignmentId !== $handover->copy->assignmentId || $current->ownerUserId !== $survivorUserId
                        || $current->revision < $revision) {
                        return AccountMergeAdmissionResult::Rejected;
                    }
                    $held = isset($collection[$row->id]);
                    $progress = isset($fences[$fenceKey]) ? $fences[$fenceKey]->appliedRevision() : 0;
                    if ($current->deleted || $current->revision > $revision || ($held && $progress >= $current->revision)) {
                        if (!(($current->deleted && !$held) || (!$current->deleted && $held && $progress >= $current->revision))) {
                            $pending = true;
                        }
                        continue;
                    }
                    $object = $held ? $collection[$row->id] : $objectClass::fromEntity($entityClass::fromRow($mapped));
                    $effects[] = static function () use ($collection, $object, $held, $mapped, $fences, $current): void {
                        $fence = $fences->rememberSnapshot($current);
                        if ($held) {
                            $object->applyDbSyncEntityUpdate($mapped, invalidateUnmapped: true);
                        } else {
                            $object->applyDbSyncUnmappedUpdate();
                            $collection[$current->rowId] = $object;
                        }
                        $fence->markApplied($current->revision);
                    };
                }
            }
        } catch (Throwable) {
            return AccountMergeAdmissionResult::Rejected;
        }
        if ($pending) {
            return AccountMergeAdmissionResult::PendingCurrent;
        }
        SourceChangeBus::whileApplyingRemote(static function () use ($effects): void {
            foreach ($effects as $effect) {
                $effect();
            }
        });

        return $effects !== [] || $empty
            ? AccountMergeAdmissionResult::Admitted : AccountMergeAdmissionResult::AlreadyCurrent;
    }

    /**
     * The executing address is the survivor; the immutable give assignment remains the loser's.
     *
     * @param int $survivorUserId Executing person
     * @param AccountMergeHandover $handover Original source batch
     * @throws HilosException When the owner, current operation, preparations or saved give disagree
     * @throws JsonException When a saved preparation or give payload cannot be decoded
     */
    private static function guard(int $survivorUserId, AccountMergeHandover $handover): void
    {
        $copy = $handover->copy;
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$survivorUserId);
        $assignment = Hilos::$db->accountOperationAssignments[$copy->assignmentId] ?? null;
        $operation = Hilos::$db->accountOperations->fresh($copy->operationId);
        $sourceOwner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$copy->loserUserId);
        if ($survivorUserId !== $copy->survivorUserId || ExecutionContext::currentAgentId() !== $owner->agentId()
            || $operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->stage !== AccountMergeStage::Go->value || $operation->finishedAt !== null
            || $operation->survivorUserId !== $survivorUserId || $operation->loserUserId !== $copy->loserUserId
            || $assignment === null || $assignment->operationId !== $copy->operationId
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Give->value
            || $assignment->userId !== $copy->loserUserId || !$assignment->owner->equals($sourceOwner)) {
            throw new InvalidFormatException('The merge handover requires its current operation and exact source and receiving owners.');
        }
        $source = self::preparation($operation, AccountMergeDuty::PrepareLoser, $copy->loserUserId);
        $target = self::preparation($operation, AccountMergeDuty::PrepareSurvivor, $survivorUserId);
        $saved = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        $give = $saved === null ? null : AccountMergeGiveResult::of($saved->payload);
        if ($saved === null || $saved->assignmentId !== $assignment->id || $saved->ownerAgentId !== $sourceOwner->agentId()
            || $give->admission->toArray() !== $handover->admission->toArray()
            || $copy->effectiveWaitDays !== $give->effectiveWaitDays
            || ($operation->mergeChoice->secondFactorFate === SecondFactorFate::BOTH) !== ($give->effectiveWaitDays !== null)
            || $copy->survivorPasswordIdentityId !== $target->passwordIdentityId
            || ($copy->loserPasswordIdentityId !== $source->passwordIdentityId
                && !($copy->loserPasswordIdentityId === null && $operation->mergeChoice->passwordFate !== PasswordFate::LOSER
                    && !in_array($source->passwordIdentityId, array_column($copy->identities, AccountMergeIdentityRow::id), true)))
            || array_column($copy->secondFactors, AccountMergeSecondFactorRow::id)
                !== ($operation->mergeChoice->secondFactorFate === SecondFactorFate::BOTH ? $source->secondFactorIds : [])) {
            throw new InvalidFormatException('The merge handover must retain its saved give manifest and reserved anchors.');
        }
    }

    /**
     * @param AccountOperation $operation Current open merge
     * @param AccountMergeDuty $duty Exact preparation side
     * @param int $userId Person reserved by that side
     * @return AccountMergePreparationResult Exact reserved preparation
     * @throws HilosException When assignment, owner, result or reservation disagrees
     * @throws JsonException When the saved preparation cannot be decoded
     */
    private static function preparation(AccountOperation $operation, AccountMergeDuty $duty, int $userId): AccountMergePreparationResult
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);
        if ($assignment === null || $assignment->operationId !== $operation->id
            || $assignment->stage !== AccountMergeStage::Preparing->value || $assignment->assignmentKey !== $duty->value
            || $assignment->userId !== $userId || !$assignment->owner->equals($owner)) {
            throw new InvalidFormatException('The merge handover requires both exact preparation assignments.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null || $result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
            throw new InvalidFormatException('The merge handover requires both assigned preparation results.');
        }
        $preparation = AccountMergePreparationResult::of($result->payload);
        if (!$preparation->isReserved()) {
            throw new InvalidFormatException('The merge handover requires both reserved preparations.');
        }

        return $preparation;
    }

    /**
     * Reads properties only for declared Entity columns, excluding every DB-only secret before an Entity exists.
     *
     * @param AccountMergeIdentityRow|AccountMergePasskeyRow|AccountMergeSecondFactorRow|AccountMergeBackupCodeRow $row Typed copy row
     * @param class-string<Entity> $entityClass Mounted row mapping
     * @return array<string, mixed> Complete mapped snapshot at the Entity boundary
     */
    private static function mapped(
        AccountMergeIdentityRow|AccountMergePasskeyRow|AccountMergeSecondFactorRow|AccountMergeBackupCodeRow $row,
        string $entityClass,
    ): array {
        $mapped = [];
        foreach ($entityClass::_columns as $column) {
            $field = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column))));
            $mapped[$column] = $row->{$field};
        }

        return $mapped;
    }
}
