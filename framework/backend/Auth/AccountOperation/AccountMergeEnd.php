<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;
use JsonException;
use Throwable;

/**
 * Prepared holder end: committed participant facts and current copy absence precede the terminal CAS.
 * The prepared coordinator calls this entry after saved results and on explicit reentry.
 */
final class AccountMergeEnd
{
    /**
     * Reconciliation commits separately, before the end transaction. No participant work is performed here.
     *
     * @param AccountOperation $operation Merge to consider outside an application transaction
     * @return bool Whether the merge has its confirmed Completed outcome
     * @throws Throwable When the holder, immutable contract or transactional storage refuses the end
     */
    public static function advance(AccountOperation $operation): bool
    {
        $holder = new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY);
        if (ExecutionContext::currentAgentId() !== $holder->agentId()) {
            throw new InvalidFormatException('Only the canonical session holder can end a prepared merge.');
        }
        if (Database::hasOpenTransactions()) {
            throw new LogicException('Merge end must enter outside an application transaction.');
        }
        $current = self::current($operation->id);
        if ($current->finishedAt === null && $current->stage === AccountMergeStage::Go->value) {
            AccountMergeOpening::reconcile($current);
        }

        return Database::transaction(DatabaseNames::PRIMARY, static function () use ($operation, $holder): bool {
            $current = self::current($operation->id);
            if ($current->finishedAt !== null && $current->outcome !== AccountOperationOutcome::Completed->value) {
                return false;
            }
            if ($current->stage !== AccountMergeStage::Go->value) {
                return false;
            }
            foreach ([AccountMergeDuty::PrepareLoser, AccountMergeDuty::PrepareSurvivor] as $duty) {
                $userId = $duty === AccountMergeDuty::PrepareLoser ? $current->loserUserId : $current->survivorUserId;
                $result = self::dutyResult($current, AccountMergeStage::Preparing, $duty, $userId,
                    new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId));
                if ($result === null || !AccountMergePreparationResult::forAccount($current, $userId)->isReserved()) {
                    return false;
                }
            }
            $source = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$current->loserUserId);
            $giveResult = self::dutyResult($current, AccountMergeStage::Go, AccountMergeDuty::Give, $current->loserUserId, $source);
            if ($giveResult === null) {
                return false;
            }
            $give = AccountMergeGiveResult::of($giveResult->payload);
            if (($current->mergeChoice->secondFactorFate === SecondFactorFate::BOTH) !== ($give->effectiveWaitDays !== null)) {
                throw new InvalidFormatException('The merge end requires the give wait from its immutable choice.');
            }
            if (AccountMergeReceiveResult::forOperation($current) === null) {
                return false;
            }
            foreach ([AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions] as $duty) {
                $userId = $duty === AccountMergeDuty::Sessions ? $current->loserUserId : $current->survivorUserId;
                $result = self::dutyResult($current, AccountMergeStage::Go, $duty, $userId, $holder);
                if ($result === null) {
                    return false;
                }
                $counts = AccountMergeSessionsResult::of($result->payload);
                if ($duty === AccountMergeDuty::SurvivorSessions && $counts->accessLog !== 0) {
                    throw new InvalidFormatException('The survivor session result cannot transfer access log rows.');
                }
            }
            if (AccountMergeCleanupResult::forOperation($current) === null || !self::projectsComplete($current)) {
                return false;
            }
            if (Hilos::$db->accountMergeCopies->hasStored($giveResult->assignmentId)) {
                return false;
            }
            if ($current->finishedAt !== null) {
                return true;
            }

            return $current->actions->finish(AccountMergeStage::Go->value, AccountOperationOutcome::Completed);
        });
    }

    /**
     * @param int $operationId Immutable operation id
     * @return AccountOperation Fresh merge with a canonical pair, sharing the transaction's operation gate
     * @throws HilosException When the lookup or merge pair refuses the call
     */
    private static function current(int $operationId): AccountOperation
    {
        $operation = Hilos::$db->accountOperations->fresh($operationId);
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->loserUserId === null || $operation->loserUserId <= 0
            || $operation->survivorUserId === null || $operation->survivorUserId <= 0
            || $operation->loserUserId === $operation->survivorUserId) {
            throw new InvalidFormatException('The merge end requires an existing merge with two distinct canonical accounts.');
        }

        return $operation;
    }

    /**
     * @param AccountOperation $operation Fresh canonical merge
     * @param AccountMergeStage $stage Immutable duty stage
     * @param AccountMergeDuty $duty Required duty even before its producer has run
     * @param int $userId Exact account represented by the duty
     * @param AccountOperationOwnerAddress $owner Canonical executor
     * @return ?AccountOperationResult Bound saved result, or null while its assignment or result is missing
     * @throws HilosException When the stored address or result binding is malformed
     */
    private static function dutyResult(
        AccountOperation $operation, AccountMergeStage $stage, AccountMergeDuty $duty,
        int $userId, AccountOperationOwnerAddress $owner,
    ): ?AccountOperationResult
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $stage->value, $duty->value);
        if ($assignment === null) {
            return null;
        }
        if ($assignment->operationId !== $operation->id || $assignment->stage !== $stage->value
            || $assignment->assignmentKey !== $duty->value || $assignment->userId !== $userId
            || !$assignment->owner->equals($owner) || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('The merge end requires the canonical duty address and empty payload.');
        }

        return self::boundResult($assignment);
    }

    /**
     * @param AccountOperationAssignment $assignment Checked immutable address
     * @return ?AccountOperationResult Exact owner-bound result, or null while unanswered
     * @throws HilosException When storage or the saved binding refuses the read
     */
    private static function boundResult(AccountOperationAssignment $assignment): ?AccountOperationResult
    {
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result !== null && ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $assignment->owner->agentId())) {
            throw new InvalidFormatException('The merge end requires each result on its exact assignment and owner.');
        }

        return $result;
    }

    /**
     * @param AccountOperation $operation Fresh canonical merge
     * @return bool Whether every expected project owner has its strict original result and no wait remains
     * @throws HilosException When the directory, expectation or saved result is malformed
     * @throws JsonException When a stored project payload cannot be decoded
     */
    private static function projectsComplete(AccountOperation $operation): bool
    {
        $composition = AccountOperationExpectedSet::of($operation->id);
        if ($composition->waiting() !== []) {
            return false;
        }
        foreach ($composition->expected() as $assignment) {
            $matches = false;
            foreach (Hilos::accountDataOwnerDirectoryClass()::declaredOwners() as $owner) {
                foreach ((new AccountMergeProjectParticipant($owner))->expectationsFor($operation->loserUserId) as $expectation) {
                    if (!$expectation->isWait() && $assignment->assignmentKey === $expectation->assignmentKey()
                        && $assignment->owner->equals($expectation->assignmentOwner())
                        && $assignment->payload->equals($expectation->payload())) {
                        $matches = true;
                    }
                }
            }
            if (!$matches || $assignment->operationId !== $operation->id || $assignment->userId !== $operation->loserUserId
                || !in_array($assignment->stage, [AccountMergeStage::Preparing->value, AccountMergeStage::Go->value], true)) {
                throw new InvalidFormatException('The merge end requires each original canonical project expectation.');
            }
            $result = self::boundResult($assignment);
            if ($result === null) {
                return false;
            }
            AccountMergeProjectResult::rowsMovedOf($result->payload);
        }

        return true;
    }
}
