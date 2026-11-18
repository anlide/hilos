<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
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
use Throwable;

/**
 * The canonical session holder selects merge duties from committed, owner-bound proofs.
 * Participants alone perform their work and write their results; sending follows each transaction.
 * SCAFFOLD: the protected holder entry is prepared; production merge switches in HIL-1999.
 */
final class AccountMergeCoordinator
{
    /**
     * @param AccountOperation $operation Merge to enter again, even after a lost answer
     * @return list<AccountOperationAssignment> Ready duties, including at most one saved give replay before receipt
     * @throws Throwable When authority, immutable proofs or transactional storage refuse progress
     */
    public static function begin(AccountOperation $operation): array
    {
        self::requireHolder();
        $current = self::current($operation->id);
        if ($current->finishedAt !== null) {
            return [];
        }

        return match (AccountMergeStage::from($current->stage)) {
            AccountMergeStage::Preparing => self::preparing($current),
            AccountMergeStage::Go => self::go($current, true),
            AccountMergeStage::Cancelling => self::cancelling($current),
        };
    }

    /**
     * @param AccountOperation $operation Merge addressed by the frame
     * @param AccountOperationAssignment $answered Immutable assignment correlated by the holder
     * @param AccountOperationAnswerSignalData $answer Notification, never proof of work
     * @return list<AccountOperationAssignment> Only ready successors of this saved result
     * @throws Throwable When authority, proofs or storage refuse progress
     */
    public static function afterAnswer(
        AccountOperation $operation, AccountOperationAssignment $answered, AccountOperationAnswerSignalData $answer,
    ): array {
        self::requireHolder();
        $current = self::current($operation->id);
        if ($current->finishedAt !== null || $answer->answer !== AccountOperationAnswerKind::Done
            || $answer->operationId !== $current->id || $answered->operationId !== $current->id
            || $answer->assignmentId !== $answered->id || $answer->stage !== $answered->stage
            || ($current->stage !== $answered->stage && !AccountMergeProjectStep::isCarriedAssignment($answered))) {
            return [];
        }
        if (!self::isCanonical($current, $answered) || self::result($answered) === null) {
            return [];
        }
        if ($current->stage === AccountMergeStage::Preparing->value) {
            return self::preparations($current) === null ? [] : self::preparing($current);
        }
        if ($current->stage === AccountMergeStage::Cancelling->value) {
            self::finishCancellation($current);

            return [];
        }
        if (AccountMergeEnd::advance($current)) {
            return [];
        }
        if (in_array($answered->assignmentKey, [AccountMergeDuty::Give->value, AccountMergeDuty::Receive->value], true)) {
            return self::goReady($current, false);
        }

        return [];
    }

    /**
     * @param AccountOperation $operation Merge whose no-give gate decides the request
     * @return AccountMergeCancellationState Durable outcome of the existing cancellation door
     * @throws Throwable When authority, validation or transactional storage refuses the request
     */
    public static function cancel(AccountOperation $operation): AccountMergeCancellationState
    {
        self::requireHolder();

        return AccountMergeCancellation::request(self::current($operation->id));
    }

    /**
     * @param AccountOperation $operation Merge whose two immutable preparations are read
     * @return ?AccountMergeRefusal First refusal in enum order, or null before both proofs or when both permit go
     * @throws Throwable When the pair, proof binding, payload or storage refuses the read
     */
    public static function refusalOf(AccountOperation $operation): ?AccountMergeRefusal
    {
        $current = self::current($operation->id);
        $preparations = self::preparations($current);
        if ($preparations === null) {
            return null;
        }
        $refusals = array_map(static fn (AccountMergePreparationResult $result): ?AccountMergeRefusal => $result->refusal, $preparations);
        if ($current->mergeChoice->passwordFate === null
            && $preparations[0]->passwordIdentityId !== null && $preparations[1]->passwordIdentityId !== null) {
            $refusals[] = AccountMergeRefusal::PasswordFateRequired;
        }
        foreach (AccountMergeRefusal::cases() as $refusal) {
            if (in_array($refusal, $refusals, true)) {
                return $refusal;
            }
        }

        return null;
    }

    /** Canonical authority and the transaction boundary apply even to terminal reentry. */
    private static function requireHolder(): void
    {
        if (ExecutionContext::currentAgentId() !== HilosAgentType::HILOS_SESSIONS_LIBRARY) {
            throw new InvalidFormatException('Only the canonical session holder can coordinate a prepared merge.');
        }
        if (Database::hasOpenTransactions()) {
            throw new LogicException('Merge coordination must enter outside an application transaction.');
        }
    }

    /**
     * @param int $operationId Durable operation identity
     * @return AccountOperation Fresh merge with a canonical pair and immutable choice
     */
    private static function current(int $operationId): AccountOperation
    {
        $current = Hilos::$db->accountOperations->fresh($operationId);
        if ($current === null || $current->kind !== AccountOperationKind::Merge
            || $current->survivorUserId === null || $current->survivorUserId <= 0
            || $current->loserUserId === null || $current->loserUserId <= 0
            || $current->survivorUserId === $current->loserUserId || $current->mergeChoice === null
            || AccountMergeStage::tryFrom($current->stage) === null) {
            throw new InvalidFormatException('Merge coordination requires an existing merge with two distinct canonical accounts.');
        }

        return $current;
    }

    /**
     * @param AccountOperation $operation Open preparing merge
     * @return list<AccountOperationAssignment> Missing preparations or the first duties after the decision
     */
    private static function preparing(AccountOperation $operation): array
    {
        AccountMergeOpening::open($operation->survivorUserId, $operation->loserUserId, $operation->mergeChoice);
        if (self::preparations($operation) === null) {
            return self::unanswered($operation, [AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::PrepareLoser]);
        }
        if (self::refusalOf($operation) !== null) {
            self::cancel($operation);

            return self::cancelling(self::current($operation->id));
        }
        Database::transaction(DatabaseNames::PRIMARY, static function () use ($operation): void {
            $current = self::current($operation->id);
            if ($current->finishedAt === null && $current->stage === AccountMergeStage::Preparing->value
                && self::preparations($current) !== null && self::refusalOf($current) === null
                && $current->actions->advance(AccountMergeStage::Preparing->value, AccountMergeStage::Go->value)) {
                self::recordGo($current);
            }
        });
        $current = self::current($operation->id);

        return $current->stage === AccountMergeStage::Go->value ? self::go($current, false) : [];
    }

    /**
     * @param AccountOperation $operation Merge with its canonical pair
     * @return ?list<AccountMergePreparationResult> Both strict saved preparations in survivor/loser order, or null
     */
    private static function preparations(AccountOperation $operation): ?array
    {
        $preparations = [];
        foreach ([AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::PrepareLoser] as $duty) {
            $assignment = self::duty($operation, $duty);
            $result = $assignment === null ? null : self::result($assignment);
            if ($result !== null) {
                $preparations[] = AccountMergePreparationResult::of($result->payload);
            }
        }

        return count($preparations) === 2 ? $preparations : null;
    }

    /**
     * @param AccountOperation $operation Open go merge
     * @param bool $replayGive Whether explicit reentry may request one saved give to resend its copy
     * @return list<AccountOperationAssignment> Ready waves, unless the durable end already stands
     */
    private static function go(AccountOperation $operation, bool $replayGive): array
    {
        Database::transaction(DatabaseNames::PRIMARY, static function () use ($operation): void {
            $current = self::current($operation->id);
            if ($current->finishedAt === null && $current->stage === AccountMergeStage::Go->value) {
                self::recordGo($current);
            }
        });

        return AccountMergeEnd::advance($operation) ? [] : self::goReady($operation, $replayGive);
    }

    /**
     * @param AccountOperation $operation Merge in the transaction that creates or repairs its fixed duties
     */
    private static function recordGo(AccountOperation $operation): void
    {
        foreach ([AccountMergeDuty::Give, AccountMergeDuty::Receive, AccountMergeDuty::Sessions,
            AccountMergeDuty::SurvivorSessions, AccountMergeDuty::Cleanup] as $duty) {
            $userId = self::userId($operation, $duty);
            Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, AccountMergeStage::Go->value, $duty->value, self::owner($operation, $duty),
                $userId, AccountOperationAssignmentPayload::fromJson('{}'),
            );
            self::duty($operation, $duty);
        }
    }

    /**
     * @param AccountOperation $operation Open go merge
     * @param bool $replayGive Whether to replay saved give once before receipt
     * @return list<AccountOperationAssignment> Ready unsaved duties, with an optional saved give replay
     */
    private static function goReady(AccountOperation $operation, bool $replayGive): array
    {
        $operation = self::current($operation->id);
        if ($operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value
            || self::preparations($operation) === null || self::refusalOf($operation) !== null) {
            return [];
        }
        $give = self::duty($operation, AccountMergeDuty::Give);
        if ($give === null || self::result($give) === null) {
            return $give === null ? [] : [$give];
        }
        $received = AccountMergeReceiveResult::forOperation($operation) !== null;
        $ready = $replayGive && !$received ? [$give] : [];
        array_push($ready, ...self::unanswered($operation, [AccountMergeDuty::Receive, AccountMergeDuty::Sessions]));
        foreach (AccountOperationExpectedSet::of($operation->id)->expected() as $assignment) {
            if (self::isCanonical($operation, $assignment) && self::result($assignment) === null) {
                $ready[] = $assignment;
            }
        }
        if ($received) {
            array_push($ready, ...self::unanswered($operation, [AccountMergeDuty::SurvivorSessions, AccountMergeDuty::Cleanup]));
        }

        return $ready;
    }

    /**
     * @param AccountOperation $operation Open cancelling merge
     * @return list<AccountOperationAssignment> Unanswered canonical release duties
     */
    private static function cancelling(AccountOperation $operation): array
    {
        AccountMergeCancellation::request($operation);
        self::finishCancellation($operation);

        return self::current($operation->id)->finishedAt !== null
            ? [] : self::unanswered($operation, [AccountMergeDuty::ReleaseLoser, AccountMergeDuty::ReleaseSurvivor]);
    }

    /**
     * @param AccountOperation $operation Cancelling merge; the existing advance reader needs this stricter proof first
     */
    private static function finishCancellation(AccountOperation $operation): void
    {
        $complete = true;
        foreach ([AccountMergeDuty::ReleaseLoser, AccountMergeDuty::ReleaseSurvivor] as $duty) {
            $assignment = self::duty($operation, $duty);
            if ($assignment === null || self::result($assignment) === null) {
                $complete = false;
            }
        }
        if ($complete) {
            AccountMergeCancellation::advance($operation);
        }
    }

    /**
     * @param AccountOperation $operation Fresh canonical merge
     * @param list<AccountMergeDuty> $duties Candidates in dispatch order
     * @return list<AccountOperationAssignment> Canonical duties without a saved result
     */
    private static function unanswered(AccountOperation $operation, array $duties): array
    {
        $ready = [];
        foreach ($duties as $duty) {
            $assignment = self::duty($operation, $duty);
            if ($assignment !== null && self::result($assignment) === null) {
                $ready[] = $assignment;
            }
        }

        return $ready;
    }

    /**
     * @param AccountMergeDuty $duty Fixed duty
     * @return AccountMergeStage Its immutable stage
     */
    private static function stage(AccountMergeDuty $duty): AccountMergeStage
    {
        return match ($duty) {
            AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::PrepareLoser => AccountMergeStage::Preparing,
            AccountMergeDuty::ReleaseSurvivor, AccountMergeDuty::ReleaseLoser => AccountMergeStage::Cancelling,
            default => AccountMergeStage::Go,
        };
    }

    /**
     * @param AccountOperation $operation Merge with its immutable pair
     * @param AccountMergeDuty $duty Fixed duty
     * @return int Account represented by the duty
     */
    private static function userId(AccountOperation $operation, AccountMergeDuty $duty): int
    {
        return in_array($duty, [AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::Receive,
            AccountMergeDuty::SurvivorSessions, AccountMergeDuty::ReleaseSurvivor], true)
            ? $operation->survivorUserId : $operation->loserUserId;
    }

    /**
     * @param AccountOperation $operation Merge with its immutable pair
     * @param AccountMergeDuty $duty Fixed duty
     * @return AccountOperationOwnerAddress Canonical personal or holder executor
     */
    private static function owner(AccountOperation $operation, AccountMergeDuty $duty): AccountOperationOwnerAddress
    {
        return in_array($duty, [AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions], true)
            ? new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY)
            : new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)self::userId($operation, $duty));
    }

    /**
     * @param AccountOperation $operation Fresh merge
     * @param AccountMergeDuty $duty Fixed duty to read
     * @return ?AccountOperationAssignment Exact canonical duty, or null before it exists
     */
    private static function duty(AccountOperation $operation, AccountMergeDuty $duty): ?AccountOperationAssignment
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, self::stage($duty)->value, $duty->value);
        if ($assignment !== null && ($assignment->operationId !== $operation->id || $assignment->stage !== self::stage($duty)->value
            || $assignment->assignmentKey !== $duty->value || $assignment->userId !== self::userId($operation, $duty)
            || !$assignment->owner->equals(self::owner($operation, $duty))
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}')))) {
            throw new InvalidFormatException('Merge coordination requires each canonical duty address and empty payload.');
        }

        return $assignment;
    }

    /**
     * @param AccountOperation $operation Fresh open merge
     * @param AccountOperationAssignment $assignment Candidate fixed duty or project expectation
     * @return bool Whether it is the original canonical assignment of this merge
     */
    private static function isCanonical(AccountOperation $operation, AccountOperationAssignment $assignment): bool
    {
        $duty = AccountMergeDuty::tryFrom($assignment->assignmentKey);
        if ($duty !== null) {
            return self::duty($operation, $duty)?->id === $assignment->id;
        }
        if ($assignment->operationId !== $operation->id || $assignment->userId !== $operation->loserUserId
            || $operation->stage !== AccountMergeStage::Go->value
            || !in_array($assignment->stage, [AccountMergeStage::Preparing->value, AccountMergeStage::Go->value], true)) {
            return false;
        }
        if ($assignment->stage === AccountMergeStage::Preparing->value) {
            return AccountMergeProjectStep::isCarriedAssignment($assignment);
        }
        foreach (Hilos::accountDataOwnerDirectoryClass()::declaredOwners() as $owner) {
            foreach ((new AccountMergeProjectParticipant($owner))->expectationsFor($operation->loserUserId) as $expectation) {
                if (!$expectation->isWait() && $assignment->assignmentKey === $expectation->assignmentKey()
                    && $assignment->owner->equals($expectation->assignmentOwner()) && $assignment->payload->equals($expectation->payload())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param AccountOperationAssignment $assignment Canonical immutable assignment
     * @return ?AccountOperationResult Strict owner-bound saved proof, or null before completion
     */
    private static function result(AccountOperationAssignment $assignment): ?AccountOperationResult
    {
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null) {
            return null;
        }
        if ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $assignment->owner->agentId()) {
            throw new InvalidFormatException('Merge coordination requires each result on its exact assignment and owner.');
        }
        match (AccountMergeDuty::tryFrom($assignment->assignmentKey)) {
            AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::PrepareLoser => AccountMergePreparationResult::of($result->payload),
            AccountMergeDuty::Give => AccountMergeGiveResult::of($result->payload),
            AccountMergeDuty::Receive => AccountMergeReceiveResult::of($result->payload),
            AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions => AccountMergeSessionsResult::of($result->payload),
            AccountMergeDuty::Cleanup => AccountMergeCleanupResult::of($result->payload),
            AccountMergeDuty::ReleaseLoser, AccountMergeDuty::ReleaseSurvivor => AccountMergeReleaseResult::releasedOf($result->payload),
            null => AccountMergeProjectResult::rowsMovedOf($result->payload),
        };

        return $result;
    }
}
