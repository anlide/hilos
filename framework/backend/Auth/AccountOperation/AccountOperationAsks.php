<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Closure;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalRefusedException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureUncapturedException;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Hilos\WiringRefusal;
use Throwable;

/**
 * Checks an assignment address and translates the participant step's result or refusal;
 * on an owner's start, runs the duties it is owed through its own assignment handler.
 */
final class AccountOperationAsks
{
    /**
     * Participant steps retain their own author, stage and idempotency checks.
     * Exception words stay in the log; the answer carries only the agreed kind and refusal fields.
     *
     * @param AccountOperationAskSignalData $ask Coordinator's assignment address
     * @param Closure(AccountOperationAssignment): AccountOperationResult $step Participant step receiving the matched assignment
     * @param string $agentId Participant agent id used for error logging
     * @return AccountOperationAnswerSignalData Correlated participant answer
     * @throws InvalidFormatException When translated refusal details cannot form a valid answer
     */
    public static function answer(AccountOperationAskSignalData $ask, Closure $step, string $agentId): AccountOperationAnswerSignalData
    {
        $answer = AccountOperationAnswerKind::Done;
        $refusedUserId = null;
        $refusedTable = null;
        try {
            $assignment = Hilos::$db->accountOperationAssignments[$ask->assignmentId] ?? null;
            if ($assignment === null || $assignment->operationId !== $ask->operationId || $assignment->stage !== $ask->stage) {
                $answer = AccountOperationAnswerKind::Refused;
            } else {
                $step($assignment);
            }
        } catch (AccountErasureUncapturedException) {
            $answer = AccountOperationAnswerKind::Uncaptured;
        } catch (AccountErasureFinalWaitsException) {
            $answer = AccountOperationAnswerKind::Waits;
        } catch (AccountErasureFinalRefusedException $e) {
            $answer = AccountOperationAnswerKind::FinalRefused;
            $refusedUserId = $e->userId;
            $refusedTable = $e->table;
        } catch (ValidationException) {
            $answer = AccountOperationAnswerKind::Refused;
        } catch (WiringRefusal $e) {
            Logger::logAgentError(
                $agentId,
                "Account operation {$ask->operationId}, stage {$ask->stage}, assignment {$ask->assignmentId} wiring refused: {$e->getMessage()}",
            );
            $answer = AccountOperationAnswerKind::Failed;
        } catch (Throwable $e) {
            Logger::logAgentError(
                $agentId,
                "Account operation {$ask->operationId}, stage {$ask->stage}, assignment {$ask->assignmentId} failed: {$e->getMessage()}",
            );
            $answer = AccountOperationAnswerKind::Failed;
        }

        return new AccountOperationAnswerSignalData(
            $ask->operationId,
            $ask->stage,
            $ask->assignmentId,
            $answer,
            $refusedUserId,
            $refusedTable,
        );
    }

    /**
     * Runs the duties a starting owner is owed through its own assignment handler, with the frame the
     * coordinator would send. The step, the outcome and the answer stay the handler's; nothing is
     * recorded here and no frame goes to the agent itself: a merged account's personal agent stops
     * after each assignment frame, and one to itself would wake it again for a duty the step keeps
     * refusing. The resume stops once the agent asks to stop; the coordinator assigns the rest.
     * Each duty writes under its own `agent` receipt named by the agent's assignment signal, unless a
     * receipt is already open. Never throws: a failed read or a failed duty is a line in the agent's log,
     * not a failed start.
     *
     * @param AbstractAgent $agent Started agent; one without an assignment signal is no participant
     * @param string $ownerType Agent type the master routes the assignment frame by
     * @param ?string $ownerIndex Agent index the master routes the assignment frame by, or null for an unindexed agent
     */
    public static function resume(AbstractAgent $agent, string $ownerType, ?string $ownerIndex): void
    {
        if ($agent::ACCOUNT_OPERATION_SIGNAL === '' || !Hilos::hasFeature(HilosFeature::AUTH)) {
            return;
        }
        try {
            $owed = AccountErasureCoordinator::owedTo(new AccountOperationOwnerAddress($ownerType, $ownerIndex));
        } catch (Throwable $e) {
            Logger::logAgentError($agent->getId(), "Account operation assignments not read at start: {$e->getMessage()}");

            return;
        }
        $resumed = 0;
        foreach ($owed as $assignment) {
            if ($agent->shouldStop()) {
                break;
            }
            try {
                $duty = static fn () => $agent->onSignalAgent(
                    new AgentSignalData(new AccountOperationAskSignalData(
                        $assignment->operationId,
                        $assignment->stage,
                        $assignment->id,
                        $assignment->owner->ownerIndex,
                    )),
                    $agent->getId(),
                    $agent::ACCOUNT_OPERATION_SIGNAL,
                );
                if (JournalReceiptScope::currentId() === null) {
                    JournalReceiptScope::run(JournalReceiptData::agent($agent::ACCOUNT_OPERATION_SIGNAL, $agent->getId()), $duty);
                } else {
                    $duty();
                }
                $resumed++;
            } catch (Throwable $e) {
                Logger::logAgentError(
                    $agent->getId(),
                    "Account operation assignment {$assignment->id} not resumed at start: {$e->getMessage()}",
                );
            }
        }
        if ($resumed > 0) {
            Logger::logAgentInfo($agent->getId(), "Resumed {$resumed} account operation assignment(s) at start");
        }
    }
}
