<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Database\View\Item\SecondFactor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;
use JsonException;
use Throwable;

/**
 * The person's agent checks its own account and records a reservation or a refusal without changing its rows.
 * SCAFFOLD: the merge coordinator sends the preparation in HIL-2000; mergeAccounts() remains live until HIL-1999.
 */
final class AccountMergePreparation
{
    /**
     * @param int $assignmentId Preparation assignment addressed to this person's agent
     * @param int $userId Account whose agent carries the step
     * @return AccountOperationResult Committed preparation or the saved result of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or stage refuses new work
     * @throws ValidationException When the assignment is not this account's own side of a merge preparation
     * @throws Throwable When reading or recording fails, preserving the original failure
     */
    public static function run(int $assignmentId, int $userId): AccountOperationResult
    {
        return AccountOperationParticipantStep::run(
            $assignmentId,
            static function (AccountOperationAssignment $assignment) use ($userId): AccountOperationResultPayload {
                $operation = Hilos::$db->accountOperations->fresh($assignment->operationId);
                if ($operation?->kind !== AccountOperationKind::Merge) {
                    throw new ValidationException('An account merge preparation must belong to a merge.');
                }
                if ($operation->stage !== AccountMergeStage::Preparing->value || $operation->finishedAt !== null) {
                    throw new ValidationException('An account merge preparation requires an open preparing merge.');
                }
                if ($assignment->assignmentKey !== AccountMergeDuty::PrepareSurvivor->value
                    && $assignment->assignmentKey !== AccountMergeDuty::PrepareLoser->value) {
                    throw new ValidationException('This assignment is not an account merge preparation.');
                }
                $survivor = $assignment->assignmentKey === AccountMergeDuty::PrepareSurvivor->value;
                if ($assignment->userId !== $userId
                    || $userId !== ($survivor ? $operation->survivorUserId : $operation->loserUserId)) {
                    throw new ValidationException('An account merge preparation must name its own side of the merge.');
                }
                if (AccountMergeReleaseResult::forAccount($operation, $userId)) {
                    throw new ValidationException('This account merge preparation has been released.');
                }
                if (!isset(Hilos::$db->users[$userId])) {
                    return AccountMergePreparationResult::refused(AccountMergeRefusal::Missing);
                }
                if (isset(Hilos::$db->userMerges[$userId])) {
                    return AccountMergePreparationResult::refused(
                        $survivor ? AccountMergeRefusal::SurvivorMerged : AccountMergeRefusal::LoserMerged,
                    );
                }
                if (Hilos::$db->accountDeletions->startedOf($userId) !== null
                    || Hilos::$db->accountOperations->openErasureCovering($userId) !== null) {
                    return AccountMergePreparationResult::refused(AccountMergeRefusal::BeingDeleted);
                }
                $holder = self::heldBy($userId);
                if ($holder !== null && $holder->id !== $operation->id) {
                    return AccountMergePreparationResult::refused(AccountMergeRefusal::Occupied);
                }
                $factors = Hilos::$db->secondFactors->confirmedOf($userId);
                $hasSecondFactor = $factors !== [];
                $choice = $operation->mergeChoice;
                $expected = $survivor ? $choice->expectedSurvivorHasSecondFactor : $choice->expectedLoserHasSecondFactor;
                if ($expected !== null && $expected !== $hasSecondFactor) {
                    return AccountMergePreparationResult::refused(AccountMergeRefusal::ProtectionChanged);
                }
                if ($survivor) {
                    if ($choice->secondFactorFate === SecondFactorFate::SURVIVOR && !$hasSecondFactor) {
                        return AccountMergePreparationResult::refused(AccountMergeRefusal::SurvivorWithoutSecondFactor);
                    }
                    if ($choice->secondFactorFate === SecondFactorFate::BOTH
                        && Hilos::$db->secondFactorResets->liveOf($userId) !== null) {
                        return AccountMergePreparationResult::refused(AccountMergeRefusal::SurvivorPendingRemoval);
                    }
                } else {
                    if ($hasSecondFactor && $choice->secondFactorFate === null) {
                        return AccountMergePreparationResult::refused(AccountMergeRefusal::SecondFactorFateRequired);
                    }
                    if (!$hasSecondFactor && $choice->secondFactorFate !== null) {
                        return AccountMergePreparationResult::refused(AccountMergeRefusal::LoserWithoutSecondFactor);
                    }
                    if ($hasSecondFactor && Hilos::$db->secondFactorResets->liveOf($userId) !== null) {
                        return AccountMergePreparationResult::refused(AccountMergeRefusal::LoserPendingRemoval);
                    }
                }

                return AccountMergePreparationResult::reserved(
                    Hilos::$db->identities->findPasswordByUser($userId)?->id,
                    array_map(static fn (SecondFactor $factor): int => $factor->id, $factors),
                );
            },
        );
    }

    /**
     * @param int $userId Account whose reservation is being read
     * @return ?AccountOperation First open merge with an unreleased saved reservation of this account
     * @throws HilosException When an operation, assignment or result cannot be read or has an invalid shape
     * @throws JsonException When a saved preparation or release cannot be decoded
     */
    public static function heldBy(int $userId): ?AccountOperation
    {
        foreach (Hilos::$db->accountOperations->openMergesNaming($userId) as $operation) {
            if (AccountMergePreparationResult::forAccount($operation, $userId)?->isReserved()
                && !AccountMergeReleaseResult::forAccount($operation, $userId)) {
                return $operation;
            }
        }

        return null;
    }
}
