<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Closure;
use Hilos\Auth\AccountOperation\DTO\AccountMergePersonalHandoverSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountMergeReceiveWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;
use Throwable;

/**
 * Joins the loser's rows of the project's personal sets into the survivor's: the loser's agent
 * gives them in one frame, the survivor's agent writes them into its own sets, and the loser's agent
 * deletes its own once the survivor's result is saved. Both duties belong to the go stage, and each
 * waits for the loser's saved give ({@see AccountMergeProjectStep::guardGive()}).
 */
final class AccountMergePersonalStep
{
    /**
     * @return bool Whether the project declares a personal set this installation carries and its person's agent joins them
     * @throws InvalidTopologyException When the project's directory of collection owners is malformed
     * @throws NamedDatabaseException When a personal collection's database binding cannot be resolved
     */
    public static function isActive(): bool
    {
        $personal = Hilos::accountDataOwnerDirectoryClass()::declaredPersonal();
        if ($personal === [] || !AccountOperationActivation::anyActivated($personal)) {
            return false;
        }
        $worker = AgentRegistry::workerClass(Hilos::appClass()::AGENTS[HilosAgentType::HILOS_USER] ?? null);

        return $worker !== null && is_a($worker, AccountMergePersonalJoin::class, true);
    }

    /**
     * The loser's side. A saved result precedes every stage check; before the survivor's result the
     * rows go out in a frame and the step waits, after it the loser's rows are deleted.
     *
     * @param int $assignmentId Immutable loser go/personal give assignment
     * @param int $userId Person represented by the current executor
     * @param AccountMergePersonalJoin $owner The loser's agent
     * @param Closure(AccountMergePersonalHandoverSignalData): void $send Sends the frame to the survivor's agent, outside any transaction
     * @return AccountOperationResult Committed deletion or its exact saved fact
     * @throws AccountMergeReceiveWaitsException When the rows went out and the survivor has not joined them yet
     * @throws Throwable When the address, the give, the agent's work or transactional storage refuses the step
     */
    public static function give(int $assignmentId, int $userId, AccountMergePersonalJoin $owner, Closure $send): AccountOperationResult
    {
        $assignment = self::assignment($assignmentId, $userId, AccountMergeDuty::PersonalGive);
        $saved = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($saved !== null) {
            self::guardResult($assignment, $saved);

            return $saved;
        }
        $operation = self::open($assignment->operationId);
        AccountMergeProjectStep::guardGive($operation);
        $receive = self::receiveOf($operation);
        $received = Hilos::$db->accountOperationResults->byAssignment($receive->id);
        if ($received === null) {
            $send(new AccountMergePersonalHandoverSignalData(
                $operation->id, AccountMergeStage::Go->value, $receive->id, (string)$operation->survivorUserId,
                $owner->personalRowsToGive(self::part($operation)),
            ));

            throw new AccountMergeReceiveWaitsException();
        }
        self::guardResult($receive, $received);
        $result = AccountOperationParticipantStep::run(
            $assignmentId,
            static function (AccountOperationAssignment $assignment) use ($owner): AccountOperationResultPayload {
                $operation = self::open($assignment->operationId);
                AccountMergeProjectStep::guardGive($operation);

                return AccountMergeProjectResult::payloadOf($owner->deleteGivenPersonalRows(self::part($operation)));
            },
        );
        self::guardResult($assignment, $result);

        return $result;
    }

    /**
     * The survivor's side. A saved result precedes every stage check, so a repeated frame joins
     * nothing twice; without a frame the step waits for one.
     *
     * @param int $assignmentId Immutable survivor go/personal receive assignment
     * @param int $userId Person represented by the current executor
     * @param AccountMergePersonalJoin $owner The survivor's agent
     * @param ?AccountMergePersonalHandoverSignalData $frame The loser's rows, or null when no frame came with the ask
     * @return AccountOperationResult Committed join or its exact saved fact
     * @throws AccountMergeReceiveWaitsException When no frame carries the rows yet
     * @throws Throwable When the address, the frame, the give, the agent's work or transactional storage refuses the step
     */
    public static function receive(
        int $assignmentId, int $userId, AccountMergePersonalJoin $owner, ?AccountMergePersonalHandoverSignalData $frame,
    ): AccountOperationResult {
        $assignment = self::assignment($assignmentId, $userId, AccountMergeDuty::PersonalReceive);
        $saved = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($saved !== null) {
            self::guardResult($assignment, $saved);

            return $saved;
        }
        if ($frame === null) {
            throw new AccountMergeReceiveWaitsException();
        }
        if ($frame->operationId !== $assignment->operationId || $frame->assignmentId !== $assignment->id) {
            throw new ValidationException('The personal merge handover requires its own receive assignment.');
        }
        AccountMergeProjectStep::guardGive(self::open($assignment->operationId));
        $result = AccountOperationParticipantStep::run(
            $assignmentId,
            static function (AccountOperationAssignment $assignment) use ($owner, $frame): AccountOperationResultPayload {
                $operation = self::open($assignment->operationId);
                AccountMergeProjectStep::guardGive($operation);

                return AccountMergeProjectResult::payloadOf($owner->joinPersonalRows(self::part($operation), $frame->rows));
            },
        );
        self::guardResult($assignment, $result);

        return $result;
    }

    /**
     * @param int $assignmentId Immutable assignment to validate
     * @param int $userId Person represented by the current executor
     * @param AccountMergeDuty $duty Personal duty the executor is asked for
     * @return AccountOperationAssignment Exact personal duty address, independent of the current stage
     * @throws HilosException When the executor, pair or immutable address refuses the call
     */
    private static function assignment(int $assignmentId, int $userId, AccountMergeDuty $duty): AccountOperationAssignment
    {
        $executor = ExecutionContext::currentAgentId();
        if ($executor === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::NoParticipant);
        }
        $assignment = Hilos::$db->accountOperationAssignments[$assignmentId] ?? null;
        if ($assignment === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::UnknownAssignment);
        }
        $operation = $assignment->operation;
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->loserUserId === null || $operation->loserUserId <= 0
            || $operation->survivorUserId === null || $operation->survivorUserId <= 0 || $operation->loserUserId === $operation->survivorUserId
            || $userId !== ($duty === AccountMergeDuty::PersonalGive ? $operation->loserUserId : $operation->survivorUserId)
            || $assignment->operationId !== $operation->id || $assignment->userId !== $userId
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== $duty->value
            || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId))
            || $assignment->owner->agentId() !== $executor
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
        }

        return $assignment;
    }

    /**
     * @param int $operationId Immutable operation id
     * @return AccountOperation Fresh merge, sharing the transaction's operation gate
     * @throws HilosException When the lookup fails or new work is outside open go
     */
    private static function open(int $operationId): AccountOperation
    {
        $operation = Hilos::$db->accountOperations->fresh($operationId);
        if ($operation === null || $operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ClosedStage);
        }

        return $operation;
    }

    /**
     * @param AccountOperation $operation Fresh open merge
     * @return AccountOperationAssignment The survivor's canonical personal receive duty
     * @throws HilosException When the duty is absent beside its give or its address is malformed
     */
    private static function receiveOf(AccountOperation $operation): AccountOperationAssignment
    {
        $receive = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::PersonalReceive->value,
        );
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->survivorUserId);
        if ($receive === null || $receive->operationId !== $operation->id || $receive->stage !== AccountMergeStage::Go->value
            || $receive->assignmentKey !== AccountMergeDuty::PersonalReceive->value || $receive->userId !== $operation->survivorUserId
            || !$receive->owner->equals($owner) || !$receive->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('A personal merge part requires its canonical survivor receive.');
        }

        return $receive;
    }

    /**
     * @param AccountOperation $operation Fresh merge with its canonical pair
     * @return AccountMergePart Operation, survivor and loser as the operation records them
     */
    private static function part(AccountOperation $operation): AccountMergePart
    {
        return new AccountMergePart($operation->id, $operation->survivorUserId, $operation->loserUserId);
    }

    /**
     * @param AccountOperationAssignment $assignment Checked personal duty
     * @param AccountOperationResult $result Saved, committed or collision-recovered result
     * @throws HilosException When the owner binding or rows payload is malformed
     * @throws JsonException When the saved rows cannot be decoded
     */
    private static function guardResult(AccountOperationAssignment $assignment, AccountOperationResult $result): void
    {
        if ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $assignment->owner->agentId()) {
            throw new InvalidFormatException('A personal merge result must belong to its exact assignment and owner.');
        }
        AccountMergeProjectResult::rowsMovedOf($result->payload);
    }
}
