<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Database;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Files\HilosFiles;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Logger;
use Throwable;

/**
 * A project owner's part of an account erasure, carried as its participant step.
 *
 * Called by the owner agent in its own frame with the assignment it was given. The part is the
 * assignment's operation and account; inside the step's transaction the owner deletes its rows of
 * that account, and its answer is recorded as the result in the same transaction, so an owner's
 * failure rolls back both. The registry files the rows pointed at leave for the files library
 * only once that transaction commits ({@see HilosFiles::remove()}), as they do for the session
 * holder; a failed removal is logged for the owner and not repeated. A repeated call returns the
 * saved result without asking the owner again.
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952).
 */
final class AccountErasureProjectStep
{
    /**
     * @param AccountErasureProjectOwner $owner Owner agent erasing its part, the step's caller
     * @param int $assignmentId Assignment the coordinator gave the owner
     * @return AccountOperationResult Committed result or the saved outcome of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws ValidationException When the assignment is not an erasure's or names no account
     * @throws Throwable When storage or the owner's erasure fails, preserving the original failure
     */
    public static function run(AccountErasureProjectOwner $owner, int $assignmentId): AccountOperationResult
    {
        return AccountOperationParticipantStep::run(
            $assignmentId,
            static function (AccountOperationAssignment $assignment) use ($owner): AccountOperationResultPayload {
                if ($assignment->operation?->kind !== AccountOperationKind::Erasure) {
                    throw new ValidationException('An account erasure part must belong to an erasure.');
                }
                $userId = $assignment->userId;
                if ($userId === null) {
                    throw new ValidationException('An account erasure part must name its account.');
                }
                $erasure = $owner->eraseAccountPart(new AccountErasurePart($assignment->operationId, $userId));
                if ($erasure->fileIds !== [] && Hilos::hasFeature(HilosFeature::FILES)) {
                    $agentId = $assignment->owner->agentId();
                    Database::afterCommit(static function () use ($erasure, $agentId, $userId): void {
                        try {
                            (Hilos::$files ?? throw new LogicException('The files door is not created'))->remove($erasure->fileIds);
                        } catch (HilosException $e) {
                            Logger::logAgentError($agentId, "Account of user {$userId} erased, but removing its files failed: {$e->getMessage()}");
                        }
                    });
                }

                return AccountErasureProjectResult::payloadOf($erasure);
            },
        );
    }
}
