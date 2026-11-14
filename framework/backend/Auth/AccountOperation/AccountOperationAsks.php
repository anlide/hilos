<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Closure;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalRefusedException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountErasureUncapturedException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Hilos\WiringRefusal;
use Throwable;

/**
 * Checks an assignment address and translates the participant step's result or refusal.
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
}
