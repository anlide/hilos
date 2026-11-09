<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Closure;
use Hilos\Auth\AccountOperation\Exception\AccountOperationResultRepeatedException;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Throwable;

final class AccountOperationParticipantStep
{
    /**
     * Commits the participant's work and immutable result together; repeats skip the work.
     * An equal saved result or unique collision recovers only after the transaction rolls back.
     *
     * @param int $assignmentId Coordinator's immutable assignment id
     * @param Closure(AccountOperationAssignment): AccountOperationResultPayload $work Participant's transactional business work
     * @return AccountOperationResult Committed result or the saved outcome of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws Throwable When storage or participant work fails, preserving the original failure
     */
    public static function run(int $assignmentId, Closure $work): AccountOperationResult
    {
        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::NoParticipant);
        }
        $assignment = Hilos::$db->accountOperationAssignments[$assignmentId] ?? null;
        if ($assignment === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::UnknownAssignment);
        }
        if ($assignment->owner->agentId() !== $agentId) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
        }
        $existing = Hilos::$db->accountOperationResults->byAssignment($assignmentId);
        if ($existing !== null) {
            return $existing;
        }
        if ($assignment->operation->finishedAt !== null || $assignment->operation->stage !== $assignment->stage) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ClosedStage);
        }

        $recordFailure = null;
        try {
            return Database::transaction(DatabaseNames::PRIMARY, static function () use (
                $assignment,
                $work,
                &$recordFailure,
            ): AccountOperationResult {
                $payload = $work($assignment);
                try {
                    return Hilos::$db->accountOperationResults->actions->recordNew($assignment, $payload);
                } catch (AccountOperationResultRepeatedException|DuplicateEntryException $failure) {
                    $recordFailure = $failure;
                    throw $failure;
                }
            });
        } catch (AccountOperationResultRepeatedException|DuplicateEntryException $failure) {
            if ($failure !== $recordFailure) {
                throw $failure;
            }
            $existing = Hilos::$db->accountOperationResults->byAssignment($assignmentId);
            if ($existing !== null) {
                return $existing;
            }

            throw $failure;
        }
    }
}
