<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Throwable;

/**
 * Each personal owner releases only its own reservation through an immutable result.
 * SCAFFOLD: the merge coordinator sends the release in HIL-2000.
 */
final class AccountMergeRelease
{
    /**
     * @param int $assignmentId Release assignment addressed to this person's agent
     * @param int $userId Account whose agent carries the step
     * @return AccountOperationResult Committed release or the saved result of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or stage refuses new work
     * @throws ValidationException When the assignment is not this account's release of an open cancelling merge
     * @throws Throwable When reading or recording fails, preserving the original failure
     */
    public static function run(int $assignmentId, int $userId): AccountOperationResult
    {
        $agentId = ExecutionContext::currentAgentId();
        if ($agentId !== null) {
            $assignment = Hilos::$db->accountOperationAssignments[$assignmentId] ?? null;
            if ($assignment !== null && $assignment->owner->agentId() === $agentId
                && Hilos::$db->accountOperationResults->byAssignment($assignmentId) === null) {
                // Admit a committed cancellation before its DB_SYNC reaches the owner's cached operation.
                Hilos::$db->accountOperations->fresh($assignment->operationId);
            }
        }

        return AccountOperationParticipantStep::run(
            $assignmentId,
            static function (AccountOperationAssignment $assignment) use ($userId): AccountOperationResultPayload {
                $operation = Hilos::$db->accountOperations->fresh($assignment->operationId);
                if ($operation?->kind !== AccountOperationKind::Merge) {
                    throw new ValidationException('An account merge release must belong to a merge.');
                }
                if ($assignment->stage !== AccountMergeStage::Cancelling->value
                    || $operation->stage !== AccountMergeStage::Cancelling->value || $operation->finishedAt !== null
                    || $operation->mergeFact() !== null) {
                    throw new ValidationException('An account merge release requires an open cancelling merge without a fact.');
                }
                if ($assignment->assignmentKey !== AccountMergeDuty::ReleaseLoser->value
                    && $assignment->assignmentKey !== AccountMergeDuty::ReleaseSurvivor->value) {
                    throw new ValidationException('This assignment is not an account merge release.');
                }
                $survivor = $assignment->assignmentKey === AccountMergeDuty::ReleaseSurvivor->value;
                if ($assignment->userId !== $userId
                    || $userId !== ($survivor ? $operation->survivorUserId : $operation->loserUserId)
                    || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId))) {
                    throw new ValidationException('An account merge release must name its own account owner and side.');
                }
                if (!$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
                    throw new ValidationException('An account merge release assignment must carry an empty object.');
                }

                return AccountMergeReleaseResult::released();
            },
        );
    }
}
