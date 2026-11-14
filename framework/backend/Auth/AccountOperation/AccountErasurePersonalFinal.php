<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalRefusedException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Throwable;

/**
 * The final of the person's agent in the erasure of an account: the last write of the account, after
 * which the agent stops (HIL-1942).
 *
 * Called by the person's agent in its own frame, on the assignment {@see AccountErasureFinal::recordMissing()}
 * wrote. Before any work every part of the account must have its result, no wait for an owner may
 * stand, and no other account may be folded into this one; a wait writes nothing. Inside the
 * participant step's transaction the agent completes the account's deletion request - the
 * operation's begun one at the root, the live one, if any, of a folded account - deletes its merge
 * row, its rename journal, its capture of the ways in and its own row, and records which request it
 * completed. A table that still references the person makes the database refuse the delete of the
 * row; the whole final rolls back, and the refusal names that table only when the schema knows it,
 * never in the engine's words. A repeated call returns the saved result without doing the work again.
 * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
 */
final class AccountErasurePersonalFinal
{
    /**
     * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
     *
     * @param int $assignmentId Final assignment the coordinator gave the person's agent
     * @param int $userId Person whose agent runs the final
     * @return AccountOperationResult Committed final or the saved one of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws AccountErasureFinalWaitsException When a part of the account has no result, a wait for an owner
     *     stands, or another account is still folded into this one
     * @throws AccountErasureFinalRefusedException When a row of another table still references the person; nothing is written
     * @throws ValidationException When the operation is no erasure, the assignment is not this person's final, or the
     *     deletion request cannot be completed
     * @throws Throwable When storage or a truth-source guard fails, preserving the original failure
     */
    public static function run(int $assignmentId, int $userId): AccountOperationResult
    {
        try {
            return AccountOperationParticipantStep::run(
                $assignmentId,
                static fn (AccountOperationAssignment $assignment): AccountOperationResultPayload => self::finish($assignment, $userId),
            );
        } catch (ForeignKeyConstraintException $e) {
            // A refused child insert (1452) is a failure of the final's own writes, not a row left behind.
            if ($e->getMysqlErrorCode() !== ForeignKeyConstraintException::ROW_IS_REFERENCED_ERROR_CODE) {
                throw $e;
            }
            $table = $e->referencingTable();
            if ($table !== null && Schema::getTable($table) === null) {
                $table = null;
            }

            throw AccountErasureFinalRefusedException::of($userId, $table, $e);
        }
    }

    /**
     * The final's work inside the participant step's transaction.
     *
     * @param AccountOperationAssignment $assignment Assignment the step resolved
     * @param int $userId Person whose agent runs the final
     * @return AccountOperationResultPayload Which request the final completed
     * @throws AccountErasureFinalWaitsException When a part of the account or a folded account waits
     * @throws ValidationException When the assignment is not this person's final of an erasure, or the request cannot be completed
     * @throws Throwable When storage or a truth-source guard fails
     */
    private static function finish(AccountOperationAssignment $assignment, int $userId): AccountOperationResultPayload
    {
        $operation = $assignment->operation;
        if ($operation?->kind !== AccountOperationKind::Erasure) {
            throw new ValidationException('An account erasure final must belong to an erasure.');
        }
        if ($assignment->assignmentKey !== AccountErasureFinal::personalKey($userId)) {
            throw new ValidationException('This assignment is not the person\'s erasure final.');
        }
        if ($assignment->userId !== $userId) {
            throw new ValidationException('An account erasure final must name its own account.');
        }
        $parts = AccountErasureFinal::missingPartsOf($assignment->operationId, $userId);
        $folded = [];
        foreach (Hilos::$db->userMerges->foldedInto($userId) as $merge) {
            $folded[] = $merge->userId;
        }
        if ($parts !== [] || $folded !== []) {
            throw AccountErasureFinalWaitsException::of($parts, $folded);
        }

        if ($operation->rootUserId === $userId) {
            $request = $operation->requestId === null ? null : Hilos::$db->accountDeletions[$operation->requestId] ?? null;
            if ($request === null || $request->startedAt === null) {
                throw new ValidationException('The erasure final needs the begun deletion request of its operation.');
            }
        } else {
            $request = Hilos::$db->accountDeletions->liveOf($userId);
        }
        if ($request !== null && !$request->actions->complete()) {
            throw new ValidationException('The erasure final could not complete the account\'s deletion request.');
        }

        // The merge row and the journal restrict the delete of the person's row, so they go first.
        Hilos::$db->userMerges->actions->deleteForUser($userId);
        Hilos::$db->userRenames->actions->deleteByUser($userId);
        $captureKey = AccountErasureCapture::personalKey($userId);
        foreach (Hilos::$db->accountOperationAssignments->forOperation($assignment->operationId) as $capture) {
            if ($capture->assignmentKey === $captureKey) {
                Hilos::$db->accountOperationResults->actions->deleteForAssignment($capture->id);
            }
        }
        if (isset(Hilos::$db->users[$userId])) {
            Hilos::$db->users[$userId]->actions->delete();
        }

        return AccountErasureFinalResult::payloadOf($request?->id);
    }
}
