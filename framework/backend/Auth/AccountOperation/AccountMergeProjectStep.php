<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Throwable;

/**
 * A project owner's part of an account merge, carried as its participant step.
 *
 * Called by the owner agent in its own frame with the assignment it was given. Before any work the
 * step checks that the assignment belongs to a merge, names that merge's loser and comes after the
 * loser gave its rows away ({@see AccountOperation::mergeFact()}); each refusal leaves nothing
 * written. The part is the survivor and the loser as the operation records them; inside the step's
 * transaction the owner moves its rows of the loser to the survivor, and its answer is recorded as
 * the result in the same transaction, so an owner's failure rolls back both. A repeated call
 * returns the saved result without asking the owner again.
 * SCAFFOLD: no live caller until the merge coordinator asks its owners (HIL-2000), live at the
 * merge switch (HIL-1999).
 */
final class AccountMergeProjectStep
{
    /**
     * @param AccountMergeProjectOwner $owner Owner agent moving its part, the step's caller
     * @param int $assignmentId Assignment the coordinator gave the owner
     * @return AccountOperationResult Committed result or the saved outcome of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws ValidationException When the assignment is not of a merge, does not name its loser, or comes before the loser's fact
     * @throws LogicException When the assignment's operation or the merge's survivor is not recorded
     * @throws Throwable When storage or the owner's move fails, preserving the original failure
     */
    public static function run(AccountMergeProjectOwner $owner, int $assignmentId): AccountOperationResult
    {
        return AccountOperationParticipantStep::run(
            $assignmentId,
            static function (AccountOperationAssignment $assignment) use ($owner): AccountOperationResultPayload {
                $operation = $assignment->operation ?? throw new LogicException('The assignment names no recorded operation.');
                if ($operation->kind !== AccountOperationKind::Merge) {
                    throw new ValidationException('An account merge part must belong to a merge.');
                }
                $loserUserId = $operation->loserUserId;
                if ($loserUserId === null || $assignment->userId !== $loserUserId) {
                    throw new ValidationException('An account merge part must name the loser of its merge.');
                }
                if ($operation->mergeFact() === null) {
                    throw new ValidationException('An account merge part waits for the loser to give its rows away.');
                }

                return AccountMergeProjectResult::payloadOf($owner->mergeAccountPart(new AccountMergePart(
                    $assignment->operationId,
                    $operation->survivorUserId ?? throw new LogicException('An account merge must record its survivor.'),
                    $loserUserId,
                )));
            },
        );
    }
}
