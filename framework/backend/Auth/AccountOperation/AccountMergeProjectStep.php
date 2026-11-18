<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;
use JsonException;
use Throwable;

/**
 * Moves a registered project owner's rows under its original immutable expectation.
 * The prepared holder delivers original expectations and correlates carried-stage answers.
 * SCAFFOLD: the live switch remains HIL-1999.
 */
final class AccountMergeProjectStep
{
    /**
     * @param AccountOperationAssignment $assignment Original preparing expectation
     * @return bool Whether its canonical project part can run in the current go stage
     * @throws HilosException When storage or the project directory refuses the lookup
     * @throws JsonException When a stored proof cannot be decoded
     */
    public static function isCarriedAssignment(AccountOperationAssignment $assignment): bool
    {
        if ($assignment->stage !== AccountMergeStage::Preparing->value) {
            return false;
        }
        $operation = Hilos::$db->accountOperations->fresh($assignment->operationId);
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value) {
            return false;
        }
        try {
            self::guardAssignment($assignment, $operation);
            self::guardGive($operation);
        } catch (ValidationException|InvalidFormatException $failure) {
            return false;
        }

        return true;
    }

    /**
     * Saved outcomes precede current-stage and give checks, including after a closed merge.
     *
     * @param AccountMergeProjectOwner $owner Owner agent moving its part in its own frame
     * @param int $assignmentId Original preparing or current-go project expectation
     * @return AccountOperationResult Committed, recovered or exact saved result
     * @throws Throwable When the address, proof, owner work or transactional storage refuses the step
     */
    public static function run(AccountMergeProjectOwner $owner, int $assignmentId): AccountOperationResult
    {
        $executor = ExecutionContext::currentAgentId();
        if ($executor === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::NoParticipant);
        }
        $assignment = Hilos::$db->accountOperationAssignments[$assignmentId] ?? null;
        if ($assignment === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::UnknownAssignment);
        }
        if ($assignment->owner->agentId() !== $executor) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
        }
        $operation = Hilos::$db->accountOperations->fresh($assignment->operationId);
        self::guardAssignment($assignment, $operation);
        $saved = Hilos::$db->accountOperationResults->byAssignment($assignmentId);
        if ($saved !== null) {
            self::guardResult($assignment, $saved);

            return $saved;
        }
        self::guardOpen($operation);
        self::guardGive($operation);
        $result = AccountOperationParticipantStep::run(
            $assignmentId,
            static function (AccountOperationAssignment $assignment) use ($owner): AccountOperationResultPayload {
                $operation = Hilos::$db->accountOperations->fresh($assignment->operationId);
                self::guardAssignment($assignment, $operation);
                self::guardOpen($operation);
                self::guardGive($operation);

                return AccountMergeProjectResult::payloadOf($owner->mergeAccountPart(new AccountMergePart(
                    $assignment->operationId, $operation->survivorUserId, $operation->loserUserId,
                )));
            },
        );
        self::guardResult($assignment, $result);

        return $result;
    }

    /**
     * @param AccountOperationAssignment $assignment Immutable project address
     * @param ?AccountOperation $operation Fresh operation, independent of its current stage
     * @throws HilosException When the pair, project registration or expectation differs
     * @throws JsonException When an expectation payload cannot be encoded
     */
    private static function guardAssignment(AccountOperationAssignment $assignment, ?AccountOperation $operation): void
    {
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge) {
            throw new ValidationException('An account merge part must belong to a merge.');
        }
        if ($operation->loserUserId === null || $operation->loserUserId <= 0
            || $assignment->userId !== $operation->loserUserId) {
            throw new ValidationException('An account merge part must name the loser of its merge.');
        }
        if ($operation->survivorUserId === null || $operation->survivorUserId <= 0
            || $operation->survivorUserId === $operation->loserUserId || $assignment->operationId !== $operation->id
            || !in_array($assignment->stage, [AccountMergeStage::Preparing->value, AccountMergeStage::Go->value], true)) {
            throw new ValidationException('An account merge part requires its canonical pair and project stage.');
        }
        foreach (Hilos::accountDataOwnerDirectoryClass()::declaredOwners() as $declared) {
            foreach ((new AccountMergeProjectParticipant($declared))->expectationsFor($operation->loserUserId) as $expectation) {
                if (!$expectation->isWait() && $assignment->assignmentKey === $expectation->assignmentKey()
                    && $assignment->userId === $expectation->userId && $assignment->owner->equals($expectation->assignmentOwner())
                    && $assignment->payload->equals($expectation->payload())) {
                    return;
                }
            }
        }

        throw new ValidationException('An account merge part requires its exact registered project expectation.');
    }

    /**
     * @param AccountOperation $operation Fresh merge
     * @throws AccountOperationStepRefusedException When new work is outside open go
     */
    private static function guardOpen(AccountOperation $operation): void
    {
        if ($operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ClosedStage);
        }
    }

    /**
     * @param AccountOperation $operation Canonical pair
     * @throws HilosException When the canonical source give or its bound strict result is absent or malformed
     */
    private static function guardGive(AccountOperation $operation): void
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
        );
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId);
        if ($assignment === null) {
            throw new ValidationException('An account merge part waits for the loser to give its rows away.');
        }
        if ($assignment->operationId !== $operation->id || $assignment->stage !== AccountMergeStage::Go->value
            || $assignment->assignmentKey !== AccountMergeDuty::Give->value || $assignment->userId !== $operation->loserUserId
            || !$assignment->owner->equals($owner) || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('A project merge part requires its canonical source give.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null) {
            throw new ValidationException('An account merge part waits for the loser to give its rows away.');
        }
        if ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
            throw new InvalidFormatException('A project merge part requires the exact owner-bound give result.');
        }
        $give = AccountMergeGiveResult::of($result->payload);
        if (($operation->mergeChoice->secondFactorFate === SecondFactorFate::BOTH) !== ($give->effectiveWaitDays !== null)) {
            throw new InvalidFormatException('The saved merge give wait must match its operation choice.');
        }
    }

    /**
     * @param AccountOperationAssignment $assignment Checked original expectation
     * @param AccountOperationResult $result Saved, committed or collision-recovered result
     * @throws HilosException When the owner binding or rows-moved payload is malformed
     * @throws JsonException When the saved rows cannot be decoded
     */
    private static function guardResult(AccountOperationAssignment $assignment, AccountOperationResult $result): void
    {
        if ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $assignment->owner->agentId()) {
            throw new InvalidFormatException('A project merge result must belong to its exact assignment and owner.');
        }
        AccountMergeProjectResult::rowsMovedOf($result->payload);
    }
}
