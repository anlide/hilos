<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Closure;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Database\View\Item\Identity;
use Hilos\Hilos;
use Throwable;

/**
 * The capture of the person's agent in the erasure of an account: its ways in and the project's
 * part, written down before they are deleted (HIL-1940).
 *
 * Called by the person's agent in its own frame, after the account closed: the root account's
 * deletion request is begun ({@see AccountErasureStart}), a folded account has its merge row.
 * Inside the participant step's transaction it reads the identifier of every way in the person
 * has, asks the project for its part in the same transaction, and records both as the result
 * ({@see AccountErasurePersonalCaptureResult}); a failure of the project's part rolls the whole
 * capture back. A repeated call returns the saved capture without reading the ways in again, even
 * when they are gone already. No address is written to a log.
 * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
 */
final class AccountErasurePersonalCapture
{
    /**
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $assignmentId Capture assignment the coordinator gave the person's agent
     * @param int $userId Person whose agent captures
     * @param Closure(): array<string, mixed> $projectPart Project's keys, asked inside the capture's transaction
     * @return AccountOperationResult Committed capture or the saved one of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws ValidationException When the operation is no erasure, the assignment is not this person's
     *     capture, or the account has not closed yet
     * @throws Throwable When storage or the project's part fails, preserving the original failure
     */
    public static function run(int $assignmentId, int $userId, Closure $projectPart): AccountOperationResult
    {
        return AccountOperationParticipantStep::run(
            $assignmentId,
            static function (AccountOperationAssignment $assignment) use ($userId, $projectPart): AccountOperationResultPayload {
                if ($assignment->operation?->kind !== AccountOperationKind::Erasure) {
                    throw new ValidationException('An account erasure capture must belong to an erasure.');
                }
                if ($assignment->assignmentKey !== AccountErasureCapture::personalKey($userId) || $assignment->userId !== $userId) {
                    throw new ValidationException('An account erasure capture must answer its own capture assignment.');
                }
                if (Hilos::$db->accountDeletions->startedOf($userId) === null && !isset(Hilos::$db->userMerges[$userId])) {
                    throw new ValidationException('An account erasure capture waits for the account to close.');
                }
                $addresses = array_map(
                    static fn (Identity $identity): string => $identity->identifier,
                    Hilos::$db->identities->listByUser($userId),
                );

                return AccountErasurePersonalCaptureResult::payloadOf($addresses, $projectPart());
            },
        );
    }
}
