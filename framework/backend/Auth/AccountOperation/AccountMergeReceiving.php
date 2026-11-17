<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountMergeReceiveWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorResetWait;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Collection\AccountMergeFences as ObjectAccountMergeFences;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\AddressablePerson;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;
use JsonException;
use Throwable;

/**
 * Applies the survivor's own protection and records its receipt in one participant transaction.
 * SCAFFOLD: HIL-2000 supplies the handover transport; HIL-1999 switches the live merge.
 */
final class AccountMergeReceiving
{
    /**
     * Saved receipts precede admission and business reads, including after operation and copy cleanup.
     * Without a handover, only already applied memory can satisfy the transferred set.
     *
     * @param int $assignmentId Immutable survivor go/receive assignment
     * @param int $userId Person represented by the executing owner
     * @param ?AccountMergeHandover $handover Original source snapshots, or null for current warm memory
     * @return AccountOperationResult Committed receipt or its exact saved result
     * @throws Throwable When validation, admission or transactional work fails, preserving the original failure
     */
    public static function run(int $assignmentId, int $userId, ?AccountMergeHandover $handover = null): AccountOperationResult
    {
        return self::receive($assignmentId, $userId, $handover, false);
    }

    /**
     * @param int $assignmentId Existing survivor receive obligation
     * @param int $userId Canonical executing person
     * @return AccountOperationResult Committed receipt or its exact saved fact
     * @throws Throwable When current snapshot, validation or participant work refuses resume
     */
    public static function resume(int $assignmentId, int $userId): AccountOperationResult
    {
        return self::receive($assignmentId, $userId, null, true);
    }

    /**
     * Metadata-only authorization for the owning snapshot reader, before any business SELECT.
     *
     * @param int $giveAssignmentId Original source obligation
     * @param int $userId Canonical executing survivor
     * @return AccountMergeGiveResult Exact saved manifest with checked preparation anchors
     * @throws HilosException When the owner, operation, obligations or immutable facts disagree
     * @throws JsonException When an immutable preparation cannot be decoded
     */
    public static function receiptManifest(int $giveAssignmentId, int $userId): AccountMergeGiveResult
    {
        $giveAssignment = Hilos::$db->accountOperationAssignments[$giveAssignmentId] ?? null;
        $operation = $giveAssignment === null ? null : Hilos::$db->accountOperations->fresh($giveAssignment->operationId);
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->survivorUserId !== $userId || $operation->loserUserId === null
            || $operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value) {
            throw new InvalidFormatException('The merge snapshot requires its current receiving operation.');
        }
        $receive = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Receive->value,
        );
        if ($receive === null) {
            throw new InvalidFormatException('The merge snapshot requires an existing receive obligation.');
        }
        self::assignment($receive->id, $userId);
        if (AccountMergeReceiveResult::forOperation($operation) !== null) {
            throw new InvalidFormatException('A completed merge receipt requires no startup snapshot.');
        }
        if (self::sourceAssignment($operation)->id !== $giveAssignmentId) {
            throw new InvalidFormatException('The merge snapshot requires its exact source obligation.');
        }
        $source = self::preparation($operation, AccountMergeDuty::PrepareLoser, $operation->loserUserId);
        $target = self::preparation($operation, AccountMergeDuty::PrepareSurvivor, $userId);
        $saved = Hilos::$db->accountOperationResults->byAssignment($giveAssignmentId);
        if ($saved === null || $saved->assignmentId !== $giveAssignmentId || $saved->ownerAgentId !== $giveAssignment->owner->agentId()) {
            throw new InvalidFormatException('The merge snapshot requires its saved source result.');
        }
        $give = AccountMergeGiveResult::of($saved->payload);
        self::guardManifest($operation, $give, $source, $target);

        return $give;
    }

    /**
     * @param int $assignmentId Exact receiving obligation
     * @param int $userId Executing survivor
     * @param ?AccountMergeHandover $handover Optional original handover
     * @param bool $resume Whether startup needs a current own snapshot
     * @return AccountOperationResult Committed or replayed receipt
     * @throws Throwable When validation, snapshot or transactional work fails
     */
    private static function receive(int $assignmentId, int $userId, ?AccountMergeHandover $handover, bool $resume): AccountOperationResult
    {
        $assignment = self::assignment($assignmentId, $userId);
        $saved = Hilos::$db->accountOperationResults->byAssignment($assignmentId);
        if ($saved !== null) {
            if ($saved->assignmentId !== $assignmentId || $saved->ownerAgentId !== $assignment->owner->agentId()) {
                throw new InvalidFormatException('The account merge receipt must belong to its exact receiving owner.');
            }
            AccountMergeReceiveResult::of($saved->payload);
            AccountMergeReceiveResult::forOperation($assignment->operation);

            return $saved;
        }
        $loserUserId = $assignment->operation->loserUserId;
        Hilos::$db->accountOperations->fresh($assignment->operationId);
        $snapshot = $resume ? Hilos::$db->accountMergeFences->receiptSnapshot(
            self::sourceAssignment($assignment->operation)->id, $userId,
        ) : null;

        return AccountOperationParticipantStep::run($assignmentId, static function () use (
            $assignment, $userId, $loserUserId, $handover, $snapshot,
        ): AccountOperationResultPayload {
            $operation = Hilos::$db->accountOperations->fresh($assignment->operationId);
            if ($operation === null || $operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value) {
                throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ClosedStage);
            }
            if ($operation->kind !== AccountOperationKind::Merge || $operation->survivorUserId !== $userId
                || $operation->loserUserId !== $loserUserId) {
                throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
            }
            AddressablePerson::require($userId);
            $source = self::preparation($operation, AccountMergeDuty::PrepareLoser, $operation->loserUserId);
            $target = self::preparation($operation, AccountMergeDuty::PrepareSurvivor, $userId);
            $giveAssignment = self::sourceAssignment($operation);
            $savedGive = Hilos::$db->accountOperationResults->byAssignment($giveAssignment->id);
            if ($savedGive === null || $savedGive->assignmentId !== $giveAssignment->id
                || $savedGive->ownerAgentId !== $giveAssignment->owner->agentId()) {
                throw new ValidationException('The account merge requires its source result before receiving.');
            }
            $give = AccountMergeGiveResult::of($savedGive->payload);
            self::guardManifest($operation, $give, $source, $target);
            if ($snapshot !== null) {
                if ($snapshot->operationId !== $operation->id || $snapshot->giveAssignmentId !== $giveAssignment->id
                    || $snapshot->survivorUserId !== $userId) {
                    throw new InvalidFormatException('The merge snapshot has different receiving coordinates.');
                }
                Hilos::$db->accountMergeFences->getObjectCollection()->hydrateReceiptSnapshot($snapshot);
            }
            if ($handover !== null) {
                if ($handover->copy->operationId !== $operation->id || $handover->copy->assignmentId !== $giveAssignment->id) {
                    throw new ValidationException('The account merge handover belongs to another assignment.');
                }
                $admission = AccountMergeAdmission::apply($userId, $handover);
                if ($admission === AccountMergeAdmissionResult::PendingCurrent) {
                    throw new AccountMergeReceiveWaitsException();
                }
                if ($admission === AccountMergeAdmissionResult::Rejected) {
                    throw new ValidationException('The account merge handover could not be accepted.');
                }
            }
            self::requireCurrent($operation, $giveAssignment->id, $give);
            self::requireProtection($operation, $give, $source, $target);
            if ($target->passwordIdentityId !== null
                && in_array($operation->mergeChoice->passwordFate, [PasswordFate::LOSER, PasswordFate::NONE], true)) {
                Hilos::$db->identities[$target->passwordIdentityId]->actions->demoteOwnForMerge($operation->id);
            }
            if ($operation->mergeChoice->secondFactorFate === SecondFactorFate::BOTH) {
                $days = max($give->effectiveWaitDays,
                    SecondFactorResetWait::of($userId)->effectiveDays(SecondFactorPolicy::current(), TimeHelper::nowMs()));
                Hilos::$db->secondFactorSettings->actions->setResetWait($userId, $days, null, null);
            }
            if ($source->secondFactorIds !== []) {
                Hilos::$db->secondFactorTrusts->actions->deleteForUser($userId);
            }

            return AccountMergeReceiveResult::received()->toPayload();
        });
    }

    /**
     * @param int $assignmentId Exact receive assignment
     * @param int $userId Executing person
     * @return AccountOperationAssignment Immutable canonical survivor address
     * @throws HilosException When storage, caller or assignment refuses the call
     */
    private static function assignment(int $assignmentId, int $userId): AccountOperationAssignment
    {
        $executor = ExecutionContext::currentAgentId();
        if ($executor === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::NoParticipant);
        }
        $assignment = Hilos::$db->accountOperationAssignments[$assignmentId] ?? null;
        if ($assignment === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::UnknownAssignment);
        }
        $operation = $assignment->operation;
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->survivorUserId !== $userId || $userId <= 0
            || $operation->loserUserId === null || $operation->loserUserId <= 0 || $operation->loserUserId === $userId
            || $assignment->operationId !== $operation->id || $assignment->userId !== $userId
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Receive->value
            || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId))
            || $assignment->owner->agentId() !== $executor
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
        }

        return $assignment;
    }

    /**
     * @param AccountOperation $operation Current canonical merge
     * @return AccountOperationAssignment Exact loser go/give assignment
     * @throws HilosException When the source assignment is missing or foreign
     */
    private static function sourceAssignment(AccountOperation $operation): AccountOperationAssignment
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
        );
        if ($assignment === null || $assignment->operationId !== $operation->id
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Give->value
            || $assignment->userId !== $operation->loserUserId
            || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId))
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('The account merge requires its exact source assignment.');
        }

        return $assignment;
    }

    /**
     * @param AccountOperation $operation Current canonical merge
     * @param AccountMergeDuty $duty Exact preparation side
     * @param int $userId Reserved person
     * @return AccountMergePreparationResult Exact reserved preparation
     * @throws HilosException When assignment, owner, result or reservation disagrees
     * @throws JsonException When the preparation cannot be decoded
     */
    private static function preparation(AccountOperation $operation, AccountMergeDuty $duty, int $userId): AccountMergePreparationResult
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);
        if ($assignment === null || $assignment->operationId !== $operation->id
            || $assignment->stage !== AccountMergeStage::Preparing->value || $assignment->assignmentKey !== $duty->value
            || $assignment->userId !== $userId || !$assignment->owner->equals($owner)
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('The account merge requires both exact preparation assignments.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null || $result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
            throw new InvalidFormatException('The account merge requires both assigned preparation results.');
        }
        $preparation = AccountMergePreparationResult::of($result->payload);
        if (!$preparation->isReserved()) {
            throw new InvalidFormatException('The account merge requires both reserved preparations.');
        }

        return $preparation;
    }

    /**
     * @param AccountOperation $operation Current merge choices
     * @param AccountMergeGiveResult $give Exact source fact
     * @param AccountMergePreparationResult $source Reserved source anchors
     * @param AccountMergePreparationResult $target Reserved survivor anchors
     * @throws InvalidFormatException When the give and preparations disagree with the chosen protection
     */
    private static function guardManifest(
        AccountOperation $operation, AccountMergeGiveResult $give,
        AccountMergePreparationResult $source, AccountMergePreparationResult $target,
    ): void {
        $choice = $operation->mergeChoice;
        $passwordKept = match ($choice->passwordFate) {
            PasswordFate::LOSER => $source->passwordIdentityId === null ? PasswordFate::NONE : PasswordFate::LOSER,
            PasswordFate::SURVIVOR => $target->passwordIdentityId === null ? PasswordFate::NONE : PasswordFate::SURVIVOR,
            PasswordFate::NONE => PasswordFate::NONE,
            null => $target->passwordIdentityId !== null ? PasswordFate::SURVIVOR
                : ($source->passwordIdentityId !== null ? PasswordFate::LOSER : PasswordFate::NONE),
        };
        $both = $choice->secondFactorFate === SecondFactorFate::BOTH;
        $secondFactorKept = match (true) {
            $both && $source->secondFactorIds !== [] && $target->secondFactorIds !== [] => AccountMergeGiveResult::SECOND_FACTOR_BOTH,
            $both && $source->secondFactorIds !== [] => AccountMergeGiveResult::SECOND_FACTOR_LOSER,
            $target->secondFactorIds !== [] => AccountMergeGiveResult::SECOND_FACTOR_SURVIVOR,
            default => AccountMergeGiveResult::SECOND_FACTOR_NONE,
        };
        if (($choice->passwordFate === null && $source->passwordIdentityId !== null && $target->passwordIdentityId !== null)
            || $give->passwordKept !== $passwordKept || $give->secondFactorKept !== $secondFactorKept
            || $both !== ($give->effectiveWaitDays !== null)
            || $give->secondFactors !== ($both ? $source->secondFactorIds : [])
            || (!$both && $give->secondFactorBackupCodes !== [])
            || ($passwordKept === PasswordFate::LOSER && !in_array($source->passwordIdentityId, $give->identities, true))) {
            throw new InvalidFormatException('The account merge source result must retain its reserved protection choices.');
        }
    }

    /**
     * Uses Object presence rather than the View's lazy offset, and never hydrates business rows from metadata.
     *
     * @param AccountOperation $operation Current canonical merge
     * @param int $giveAssignmentId Original source assignment
     * @param AccountMergeGiveResult $give Original rows and revisions
     * @throws HilosException When metadata or row anchors refuse the check, or applied memory is still pending
     */
    private static function requireCurrent(AccountOperation $operation, int $giveAssignmentId, AccountMergeGiveResult $give): void
    {
        $fences = Hilos::$db->mountedObjectCollection(HilosDbContext::accountMergeFences);
        if (!$fences instanceof ObjectAccountMergeFences) {
            throw new InvalidFormatException('The account merge requires its transfer metadata.');
        }
        foreach (ObjectAccountMergeFences::BUSINESS_COLLECTIONS as $key) {
            $collection = Hilos::$db->mountedObjectCollection($key);
            if ($collection === null || !$collection->cachesRows()) {
                throw new InvalidFormatException('The account merge requires its held row collections.');
            }
            foreach ($give->admission->{$key} as $stamp) {
                $fenceKey = $key . ':' . $stamp->id;
                $current = $fences->fresh($fenceKey);
                if ($current === null || $current->operationId !== $operation->id || $current->assignmentId !== $giveAssignmentId
                    || $current->ownerUserId !== $operation->survivorUserId || $current->revision < $stamp->revision) {
                    throw new InvalidFormatException('The account merge transfer metadata has different coordinates.');
                }
                $held = isset($collection[$stamp->id]);
                if ($current->deleted) {
                    if ($held) {
                        throw new AccountMergeReceiveWaitsException();
                    }
                    continue;
                }
                if (!$held || !isset($fences[$fenceKey]) || $fences[$fenceKey]->appliedRevision() < $current->revision) {
                    throw new AccountMergeReceiveWaitsException();
                }
                $row = $collection[$stamp->id];
                if ($row->id !== $stamp->id || $row->userId !== $operation->survivorUserId || !$row->isRelated() || $row->hasChanges()) {
                    throw new InvalidFormatException('The account merge held row has a different owner or anchor.');
                }
                if ($key === HilosDbContext::passkeyCredentials) {
                    $identities = Hilos::$db->mountedObjectCollection(HilosDbContext::identities);
                    if (!in_array($row->identityId, $give->identities, true) || !isset($identities[$row->identityId])
                        || $identities[$row->identityId]->userId !== $operation->survivorUserId
                        || $identities[$row->identityId]->type !== IdentityType::PASSKEY) {
                        throw new InvalidFormatException('The account merge held credential has a different identity anchor.');
                    }
                }
            }
        }
    }

    /**
     * @param AccountOperation $operation Chosen protection
     * @param AccountMergeGiveResult $give Saved actual outcomes
     * @param AccountMergePreparationResult $source Reserved source anchors
     * @param AccountMergePreparationResult $target Reserved own anchors
     * @throws HilosException When a captured password or required confirmed factor no longer has its identity
     */
    private static function requireProtection(
        AccountOperation $operation, AccountMergeGiveResult $give,
        AccountMergePreparationResult $source, AccountMergePreparationResult $target,
    ): void {
        if ($target->passwordIdentityId !== null) {
            $password = Hilos::$db->identities[$target->passwordIdentityId] ?? null;
            if ($password === null || $password->id !== $target->passwordIdentityId || $password->userId !== $operation->survivorUserId
                || $password->type !== IdentityType::PASSWORD) {
                throw new ValidationException('The account merge password no longer has its reserved identity.');
            }
        }
        $identities = Hilos::$db->mountedObjectCollection(HilosDbContext::identities);
        if ($give->passwordKept === PasswordFate::LOSER
            && (!isset($identities[$source->passwordIdentityId])
                || $identities[$source->passwordIdentityId]->type !== IdentityType::PASSWORD)) {
            throw new ValidationException('The account merge password no longer has its reserved identity.');
        }
        $factors = Hilos::$db->mountedObjectCollection(HilosDbContext::secondFactors);
        foreach ($give->secondFactors as $id) {
            if (!isset($factors[$id]) || $factors[$id]->confirmedAt === null) {
                throw new ValidationException('The account merge confirmed protection no longer has its reserved identity.');
            }
        }
        foreach ($target->secondFactorIds as $id) {
            $factor = Hilos::$db->secondFactors[$id] ?? null;
            if ($factor === null || $factor->id !== $id || $factor->userId !== $operation->survivorUserId || $factor->confirmedAt === null) {
                throw new ValidationException('The account merge own protection no longer has its reserved identity.');
            }
        }
    }
}
