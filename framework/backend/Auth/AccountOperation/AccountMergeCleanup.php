<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountMergeCleanupWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;
use Throwable;

/** Removes the source's private copy and records its nonsecret fact in one participant transaction. */
final class AccountMergeCleanup
{
    /**
     * Saved cleanup precedes current-stage, copy and business reads, even after a later merge.
     * SCAFFOLD: the coordinator produces this assignment in HIL-2000; the live switch remains HIL-1999.
     *
     * @param int $assignmentId Immutable loser go/cleanup assignment
     * @param int $userId Person represented by the current owner
     * @return AccountOperationResult Committed cleanup or its exact saved fact
     * @throws Throwable When the assignment, receipt or transactional storage refuses cleanup
     */
    public static function run(int $assignmentId, int $userId): AccountOperationResult
    {
        $assignment = self::assignment($assignmentId, $userId);
        $saved = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($saved !== null) {
            self::guardResult($assignment, $saved);

            return $saved;
        }
        $survivorUserId = $assignment->operation->survivorUserId;
        $result = AccountOperationParticipantStep::run($assignmentId, static function () use (
            $assignment, $survivorUserId,
        ): AccountOperationResultPayload {
            $operation = Hilos::$db->accountOperations->fresh($assignment->operationId);
            if ($operation === null || $operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value) {
                throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ClosedStage);
            }
            if ($operation->kind !== AccountOperationKind::Merge || $operation->loserUserId !== $assignment->userId
                || $operation->survivorUserId !== $survivorUserId) {
                throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
            }
            $give = self::give($operation);
            if (AccountMergeReceiveResult::forOperation($operation) === null) {
                throw new AccountMergeCleanupWaitsException();
            }
            $copy = Hilos::$db->accountMergeCopies[$give->id] ?? null;
            if ($copy !== null) {
                $copy->actions->deleteAfterReceipt();
            }
            if (Hilos::$db->accountMergeCopies->hasStored($give->id)) {
                throw new InvalidFormatException('The merge cleanup requires the private copy to be absent.');
            }

            return AccountMergeCleanupResult::cleaned()->toPayload();
        });
        self::guardResult($assignment, $result);

        return $result;
    }

    /**
     * @param int $assignmentId Immutable assignment to validate
     * @param int $userId Person represented by the current executor
     * @return AccountOperationAssignment Exact cleanup address, independent of the current stage
     * @throws HilosException When the executor, pair or immutable address refuses the call
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
            || $operation->loserUserId !== $userId || $userId <= 0
            || $operation->survivorUserId === null || $operation->survivorUserId <= 0 || $operation->survivorUserId === $userId
            || $assignment->operationId !== $operation->id || $assignment->userId !== $userId
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Cleanup->value
            || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId))
            || $assignment->owner->agentId() !== $executor
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
        }

        return $assignment;
    }

    /**
     * @param AccountOperation $operation Current open merge
     * @return AccountOperationAssignment Canonical source give, bound to its strict committed result
     * @throws HilosException When the source address, result or effective wait is missing or malformed
     */
    private static function give(AccountOperation $operation): AccountOperationAssignment
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
        );
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId);
        if ($assignment === null || $assignment->operationId !== $operation->id
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Give->value
            || $assignment->userId !== $operation->loserUserId || !$assignment->owner->equals($owner)
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('The merge cleanup requires its canonical source give assignment.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null || $result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
            throw new InvalidFormatException('The merge cleanup requires its exact saved give result.');
        }
        $give = AccountMergeGiveResult::of($result->payload);
        if (($operation->mergeChoice->secondFactorFate === SecondFactorFate::BOTH) !== ($give->effectiveWaitDays !== null)) {
            throw new InvalidFormatException('The saved merge give wait does not match its operation choice.');
        }

        return $assignment;
    }

    /**
     * @param AccountOperationAssignment $assignment Checked immutable cleanup address
     * @param AccountOperationResult $result Saved, committed or recovered result
     * @throws HilosException When the result binding or strict payload refuses replay
     */
    private static function guardResult(AccountOperationAssignment $assignment, AccountOperationResult $result): void
    {
        if ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $assignment->owner->agentId()) {
            throw new InvalidFormatException('The saved merge cleanup must belong to its exact assignment and source owner.');
        }
        AccountMergeCleanupResult::of($result->payload);
    }
}
