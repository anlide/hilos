<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\AccountOperation\AccountMergeCleanupResult;
use Hilos\Auth\AccountOperation\AccountMergeCoordinator;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeProjectResult;
use Hilos\Auth\AccountOperation\AccountMergeReceiveResult;
use Hilos\Auth\AccountOperation\AccountMergeReleaseResult;
use Hilos\Auth\AccountOperation\AccountMergeSessionsResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use JsonException;
use Throwable;

/**
 * Observes durable merge metadata through mounted readers, without advancing business work.
 * Process-local answers, absence and shape failures are derived memory, never shared obligations.
 * Source mirrors, rollback reversals and index start/stop discard both cache identities.
 */
final class AccountMergeProgressResolver
{
    /** @var array<string, ?AccountMergeProgress> Allowlisted answers or absence, by account or exact operation */
    private static array $answers = [];

    /** @var array<string, InvalidFormatException|InvalidJsonException> Shape failures, separate from retryable DB failures */
    private static array $failures = [];

    /**
     * @param int $userId Account named by either side
     * @return ?AccountMergeProgress Latest open naming merge, otherwise the latest saved closed merge
     * @throws Throwable When sources are unavailable or a saved fact is malformed
     */
    public static function of(int $userId): ?AccountMergeProgress
    {
        return self::remember('user:' . $userId, static function () use ($userId): ?AccountMergeProgress {
            self::requireDatabase();
            $operation = null;
            foreach (Hilos::$db->accountOperations->openMergesNaming($userId) as $candidate) {
                if (($candidate->survivorUserId === $userId || $candidate->loserUserId === $userId)
                    && ($operation === null || $candidate->id > $operation->id)) {
                    $operation = $candidate;
                }
            }
            $operation ??= Hilos::$db->accountOperations->latestMergeNaming($userId);

            return $operation === null ? null : self::forOperation($operation->id);
        });
    }

    /**
     * @param int $operationId Exact persisted primary key, without reelecting either account
     * @return ?AccountMergeProgress Saved merge, or null for absence or another operation kind
     * @throws Throwable When sources are unavailable or a saved fact is malformed
     */
    public static function forOperation(int $operationId): ?AccountMergeProgress
    {
        return self::remember('operation:' . $operationId, static function () use ($operationId): ?AccountMergeProgress {
            self::requireDatabase();
            $operation = Hilos::$db->accountOperations->fresh($operationId);

            return $operation === null || $operation->kind !== AccountOperationKind::Merge ? null : self::fromOperation($operation);
        });
    }

    /**
     * Reads every open merge by primary id, using the exact operation projection.
     *
     * @return array<int, AccountMergeProgress> Open merge progress keyed by operation id
     * @throws Throwable When sources are unavailable or a saved fact is malformed
     */
    public static function open(): array
    {
        self::requireDatabase();
        $open = [];
        $afterId = 0;
        do {
            $page = Hilos::$db->accountOperations->openPage(AccountOperationKind::Merge, $afterId, ObjectAccountOperations::PAGE_LIMIT_MAX);
            foreach ($page as $operationId => $operation) {
                $afterId = (int) $operationId;
                if ($operation->finishedAt === null) {
                    $progress = self::forOperation($afterId);
                    if ($progress !== null && $progress->outcome === null) {
                        $open[$afterId] = $progress;
                    }
                }
            }
        } while (count($page) === ObjectAccountOperations::PAGE_LIMIT_MAX);

        return $open;
    }

    /** Discards answers, absence and malformed-fact failures for both read entrances. */
    public static function forgetAll(): void
    {
        self::$answers = [];
        self::$failures = [];
    }

    /**
     * @param string $key Account or operation cache identity
     * @param callable(): ?AccountMergeProgress $read Mounted source reader
     * @return ?AccountMergeProgress Derived answer or remembered absence
     * @throws Throwable When the read fails; only shape failures are remembered
     */
    private static function remember(string $key, callable $read): ?AccountMergeProgress
    {
        if (isset(self::$failures[$key])) {
            throw self::$failures[$key];
        }
        if (!array_key_exists($key, self::$answers)) {
            try {
                self::$answers[$key] = $read();
            } catch (JsonException $failure) {
                throw self::$failures[$key] = new InvalidJsonException('Saved merge observation must contain valid JSON.', previous: $failure);
            } catch (InvalidFormatException|InvalidJsonException $failure) {
                throw self::$failures[$key] = $failure;
            }
        }

        return self::$answers[$key];
    }

    /** @throws DatabaseException When the database context has not been initialized */
    private static function requireDatabase(): void
    {
        if (Hilos::$db === null) {
            throw new DatabaseException('Merge observation requires its database sources.');
        }
    }

    /**
     * @param AccountOperation $operation Fresh saved merge
     * @return AccountMergeProgress One projection shared by discovery and exact lookup
     * @throws Throwable When a saved pair, stage, outcome, assignment or result is invalid or inaccessible
     */
    private static function fromOperation(AccountOperation $operation): AccountMergeProgress
    {
        if ($operation->id === null || $operation->id <= 0 || $operation->survivorUserId === null || $operation->survivorUserId <= 0
            || $operation->loserUserId === null || $operation->loserUserId <= 0 || $operation->survivorUserId === $operation->loserUserId
            || AccountMergeStage::tryFrom($operation->stage) === null
            || (($operation->finishedAt === null) !== ($operation->outcome === null))
            || ($operation->outcome !== null && AccountOperationOutcome::tryFrom($operation->outcome) === null)) {
            throw new InvalidFormatException('Merge observation requires a saved distinct pair, known stage and durable outcome.');
        }
        $assignments = Hilos::$db->accountOperationAssignments->forOperation($operation->id);
        $parts = [];
        $seen = [];
        $given = false;
        $received = false;
        foreach ($assignments as $assignment) {
            $duty = AccountMergeDuty::tryFrom($assignment->assignmentKey);
            if ($duty !== null) {
                self::guardDuty($operation, $assignment, $duty);
                $done = self::dutyDone($operation, $assignment, $duty);
                $participant = $duty->value;
                if ($duty === AccountMergeDuty::Give) {
                    $given = $done;
                } elseif ($duty === AccountMergeDuty::Receive) {
                    $received = $done;
                }
            } elseif (AccountOperationExpectation::isExpectKey($assignment->assignmentKey)) {
                $participant = self::guardExpectation($operation, $assignment);
                $result = self::boundResult($assignment);
                if ($result !== null) {
                    AccountMergeProjectResult::rowsMovedOf($result->payload);
                }
                $done = $result !== null;
            } else {
                if (AccountOperationExpectation::isWaitKey($assignment->assignmentKey)) {
                    self::guardExpectation($operation, $assignment);
                }
                continue;
            }
            $identity = $assignment->stage . ':' . $assignment->assignmentKey;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $parts[] = [
                AccountMergeProgress::participant => $participant,
                AccountMergeProgress::owner => $assignment->ownerType,
                AccountMergeProgress::userId => $assignment->userId,
                AccountMergeProgress::done => $done,
            ];
        }
        $waits = [];
        $seen = [];
        foreach ((new AccountOperationExpectedSet(array_values(iterator_to_array($assignments))))->waiting() as $assignment) {
            $participant = self::guardExpectation($operation, $assignment);
            if (isset($seen[$assignment->assignmentKey])) {
                continue;
            }
            $seen[$assignment->assignmentKey] = true;
            $waits[] = [AccountMergeProgress::participant => $participant, AccountMergeProgress::userId => $assignment->userId];
        }
        $refusal = AccountMergeCoordinator::refusalOf($operation);
        $state = match (true) {
            $refusal !== null => AccountMergeProgress::REFUSAL,
            $operation->finishedAt !== null => AccountMergeProgress::END,
            $operation->stage === AccountMergeStage::Preparing->value => AccountMergeProgress::PREPARING,
            default => AccountMergeProgress::WAIT,
        };

        return new AccountMergeProgress(
            $operation->id, $operation->survivorUserId, $operation->loserUserId, $state, $operation->stage, $operation->outcome,
            $given, $received, $operation->mergeChoice,
            $refusal === null ? null : [AccountMergeProgress::code => $refusal->value,
                AccountMergeProgress::reason => AccountMergeRefusalText::of($operation, $refusal)],
            $parts, $waits,
        );
    }

    /**
     * @param AccountOperation $operation Saved merge
     * @param AccountOperationAssignment $assignment Existing fixed duty
     * @param AccountMergeDuty $duty Its literal key
     * @throws Throwable When any canonical coordinate or the empty payload differs
     */
    private static function guardDuty(AccountOperation $operation, AccountOperationAssignment $assignment, AccountMergeDuty $duty): void
    {
        $stage = match ($duty) {
            AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::PrepareLoser => AccountMergeStage::Preparing,
            AccountMergeDuty::ReleaseSurvivor, AccountMergeDuty::ReleaseLoser => AccountMergeStage::Cancelling,
            default => AccountMergeStage::Go,
        };
        $userId = match ($duty) {
            AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::Receive,
            AccountMergeDuty::SurvivorSessions, AccountMergeDuty::ReleaseSurvivor => $operation->survivorUserId,
            default => $operation->loserUserId,
        };
        $owner = in_array($duty, [AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions], true)
            ? new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY)
            : new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);
        if ($assignment->operationId !== $operation->id || $assignment->stage !== $stage->value
            || $assignment->assignmentKey !== $duty->value || $assignment->userId !== $userId || !$assignment->owner->equals($owner)
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('Merge observation requires each canonical fixed duty address and empty payload.');
        }
    }

    /**
     * @param AccountOperationAssignment $assignment Canonically bound assignment
     * @return ?AccountOperationResult Exact saved result, or pending
     * @throws Throwable When result binding or the mounted reader is invalid
     */
    private static function boundResult(AccountOperationAssignment $assignment): ?AccountOperationResult
    {
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result !== null && ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $assignment->owner->agentId())) {
            throw new InvalidFormatException('Merge observation requires each result to name its exact assignment and owner.');
        }

        return $result;
    }

    /**
     * @param AccountOperation $operation Saved merge
     * @param AccountOperationAssignment $assignment Checked fixed duty
     * @param AccountMergeDuty $duty Its literal key
     * @return bool Whether its strict saved result exists, including an answered preparation refusal
     * @throws Throwable When the saved result is malformed or inaccessible
     */
    private static function dutyDone(AccountOperation $operation, AccountOperationAssignment $assignment, AccountMergeDuty $duty): bool
    {
        $result = self::boundResult($assignment);
        if ($result === null) {
            return false;
        }
        return match ($duty) {
            AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::PrepareLoser =>
                AccountMergePreparationResult::forAccount($operation, $assignment->userId) !== null,
            AccountMergeDuty::ReleaseSurvivor, AccountMergeDuty::ReleaseLoser =>
                AccountMergeReleaseResult::forAccount($operation, $assignment->userId),
            AccountMergeDuty::Receive => AccountMergeReceiveResult::forOperation($operation) !== null,
            AccountMergeDuty::Cleanup => AccountMergeCleanupResult::forOperation($operation) !== null,
            AccountMergeDuty::Give => AccountMergeGiveResult::of($result->payload) !== null,
            AccountMergeDuty::Sessions, AccountMergeDuty::SurvivorSessions => AccountMergeSessionsResult::of($result->payload) !== null,
        };
    }

    /**
     * @param AccountOperation $operation Saved merge
     * @param AccountOperationAssignment $assignment Expected duty or wait, preserving its original stage and id
     * @return string Payload's participant without splitting the identity key
     * @throws Throwable When expectation coordinates or payload are not self-consistent
     */
    private static function guardExpectation(AccountOperation $operation, AccountOperationAssignment $assignment): string
    {
        $participant = AccountOperationExpectation::participantOf($assignment->payload);
        $expectation = new AccountOperationExpectation($participant, $assignment->userId,
            AccountOperationExpectation::isWaitKey($assignment->assignmentKey) ? null : $assignment->owner);
        if ($participant === '' || $assignment->operationId !== $operation->id || $assignment->userId !== $operation->loserUserId
            || !in_array($assignment->stage, [AccountMergeStage::Preparing->value, AccountMergeStage::Go->value], true)
            || $assignment->assignmentKey !== $expectation->assignmentKey()
            || !$assignment->owner->equals($expectation->assignmentOwner()) || !$assignment->payload->equals($expectation->payload())) {
            throw new InvalidFormatException('Merge observation requires each original self-consistent project expectation.');
        }

        return $participant;
    }
}
