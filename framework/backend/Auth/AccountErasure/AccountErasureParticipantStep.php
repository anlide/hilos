<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountErasure;

use Closure;
use Hilos\Auth\AccountErasure\Exception\AccountErasureResultRepeatedException;
use Hilos\Auth\AccountErasure\Exception\AccountErasureStepRefusedException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\View\Item\AccountErasureAssignment;
use Hilos\Database\View\Item\AccountErasureResult;
use Hilos\Hilos;
use Throwable;

final class AccountErasureParticipantStep
{
    /**
     * Commits the participant's work and immutable result together; repeats skip the work.
     * An equal saved result or unique collision recovers only after the transaction rolls back.
     *
     * @param int $assignmentId Coordinator's immutable assignment id
     * @param Closure(AccountErasureAssignment): AccountErasureResultPayload $work Participant's transactional business work
     * @return AccountErasureResult Committed result or the saved outcome of an earlier attempt
     * @throws AccountErasureStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws Throwable When storage or participant work fails, preserving the original failure
     */
    public static function run(int $assignmentId, Closure $work): AccountErasureResult
    {
        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            throw new AccountErasureStepRefusedException(AccountErasureStepRefusal::NoParticipant);
        }
        $assignment = Hilos::$db->accountErasureAssignments[$assignmentId] ?? null;
        if ($assignment === null) {
            throw new AccountErasureStepRefusedException(AccountErasureStepRefusal::UnknownAssignment);
        }
        if ($assignment->owner->agentId() !== $agentId) {
            throw new AccountErasureStepRefusedException(AccountErasureStepRefusal::ForeignAssignment);
        }
        $existing = Hilos::$db->accountErasureResults->byAssignment($assignmentId);
        if ($existing !== null) {
            return $existing;
        }
        if ($assignment->operation->finishedAt !== null || $assignment->operation->stage !== $assignment->stage) {
            throw new AccountErasureStepRefusedException(AccountErasureStepRefusal::ClosedStage);
        }

        $recordFailure = null;
        try {
            return Database::transaction(DatabaseNames::PRIMARY, static function () use ($assignment, $work, &$recordFailure): AccountErasureResult {
                $payload = $work($assignment);
                try {
                    return Hilos::$db->accountErasureResults->actions->recordNew($assignment, $payload);
                } catch (AccountErasureResultRepeatedException|DuplicateEntryException $failure) {
                    $recordFailure = $failure;
                    throw $failure;
                }
            });
        } catch (AccountErasureResultRepeatedException|DuplicateEntryException $failure) {
            if ($failure !== $recordFailure) {
                throw $failure;
            }
            $existing = Hilos::$db->accountErasureResults->byAssignment($assignmentId);
            if ($existing !== null) {
                return $existing;
            }

            throw $failure;
        }
    }
}
