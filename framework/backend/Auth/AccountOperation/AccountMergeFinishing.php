<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;
use JsonException;

/** Persisted source context, independent of mutable business rows, copy cleanup and operation end. */
final class AccountMergeFinishing
{
    /**
     * @param int $userId Canonical source person
     * @return ?AccountOperation Exact prepared source context, or null without a saved source give
     * @throws HilosException When metadata, immutable proofs or the tombstone disagree
     * @throws JsonException When an immutable preparation cannot be decoded
     */
    public static function forLoser(int $userId): ?AccountOperation
    {
        $assignment = Hilos::$db->accountOperationAssignments->mergeGiveOfLoser($userId);
        if ($assignment === null) {
            return null;
        }
        $operation = Hilos::$db->accountOperations->fresh($assignment->operationId);
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $userId <= 0 || $operation->loserUserId !== $userId
            || $operation->survivorUserId === null || $operation->survivorUserId <= 0 || $operation->survivorUserId === $userId
            || $assignment->operationId !== $operation->id || $assignment->userId !== $userId
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Give->value
            || !$assignment->owner->equals($owner)
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('The merge finishing context requires its canonical source give.');
        }
        $canonical = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
        );
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($canonical === null || $canonical->id !== $assignment->id || $result === null
            || $result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
            throw new InvalidFormatException('The merge finishing context requires its exact saved source result.');
        }
        $source = self::preparation($operation, AccountMergeDuty::PrepareLoser, $userId);
        $target = self::preparation($operation, AccountMergeDuty::PrepareSurvivor, $operation->survivorUserId);
        self::guardManifest($operation, AccountMergeGiveResult::of($result->payload), $source, $target);
        $tombstone = Hilos::$db->userMerges[$userId] ?? null;
        if ($tombstone !== null && $tombstone->survivorUserId !== null && $tombstone->survivorUserId !== $operation->survivorUserId) {
            throw new InvalidFormatException('The merge finishing context has a different tombstone survivor.');
        }

        return $operation;
    }

    /**
     * @param AccountOperation $operation Current canonical merge
     * @param AccountMergeDuty $duty Exact preparation side
     * @param int $userId Reserved person
     * @return AccountMergePreparationResult Exact reserved preparation
     * @throws HilosException When assignment, owner, result or reservation disagrees
     * @throws JsonException When the preparation cannot be decoded
     */
    private static function preparation(AccountOperation $operation, AccountMergeDuty $duty, int $userId): AccountMergePreparationResult
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);
        if ($assignment === null || $assignment->operationId !== $operation->id
            || $assignment->stage !== AccountMergeStage::Preparing->value || $assignment->assignmentKey !== $duty->value
            || $assignment->userId !== $userId || !$assignment->owner->equals($owner)
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('The account merge requires both exact preparation assignments.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null || $result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
            throw new InvalidFormatException('The account merge requires both assigned preparation results.');
        }
        $preparation = AccountMergePreparationResult::of($result->payload);
        if (!$preparation->isReserved()) {
            throw new InvalidFormatException('The account merge requires both reserved preparations.');
        }

        return $preparation;
    }

    /**
     * @param AccountOperation $operation Current merge choices
     * @param AccountMergeGiveResult $give Exact source fact
     * @param AccountMergePreparationResult $source Reserved source anchors
     * @param AccountMergePreparationResult $target Reserved survivor anchors
     * @throws InvalidFormatException When the give and preparations disagree with the chosen protection
     */
    private static function guardManifest(
        AccountOperation $operation, AccountMergeGiveResult $give,
        AccountMergePreparationResult $source, AccountMergePreparationResult $target,
    ): void {
        $choice = $operation->mergeChoice;
        $passwordKept = match ($choice->passwordFate) {
            PasswordFate::LOSER => $source->passwordIdentityId === null ? PasswordFate::NONE : PasswordFate::LOSER,
            PasswordFate::SURVIVOR => $target->passwordIdentityId === null ? PasswordFate::NONE : PasswordFate::SURVIVOR,
            PasswordFate::NONE => PasswordFate::NONE,
            null => $target->passwordIdentityId !== null ? PasswordFate::SURVIVOR
                : ($source->passwordIdentityId !== null ? PasswordFate::LOSER : PasswordFate::NONE),
        };
        $both = $choice->secondFactorFate === SecondFactorFate::BOTH;
        $secondFactorKept = match (true) {
            $both && $source->secondFactorIds !== [] && $target->secondFactorIds !== [] => AccountMergeGiveResult::SECOND_FACTOR_BOTH,
            $both && $source->secondFactorIds !== [] => AccountMergeGiveResult::SECOND_FACTOR_LOSER,
            $target->secondFactorIds !== [] => AccountMergeGiveResult::SECOND_FACTOR_SURVIVOR,
            default => AccountMergeGiveResult::SECOND_FACTOR_NONE,
        };
        if (($choice->passwordFate === null && $source->passwordIdentityId !== null && $target->passwordIdentityId !== null)
            || $give->passwordKept !== $passwordKept || $give->secondFactorKept !== $secondFactorKept
            || $both !== ($give->effectiveWaitDays !== null)
            || $give->secondFactors !== ($both ? $source->secondFactorIds : [])
            || (!$both && $give->secondFactorBackupCodes !== [])
            || ($passwordKept === PasswordFate::LOSER && !in_array($source->passwordIdentityId, $give->identities, true))) {
            throw new InvalidFormatException('The account merge source result must retain its reserved protection choices.');
        }
    }
}
