<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use Throwable;

/** The shared domain sentences used by both legacy merging and saved merge observation. */
final class AccountMergeRefusalText
{
    /**
     * @param AccountOperation $operation Saved merge whose refusal is being named
     * @param AccountMergeRefusal $refusal Coordinator's selected refusal
     * @return string Existing template with its exact account arguments
     * @throws Throwable When stored preparation binding or label sources cannot be read
     */
    public static function of(AccountOperation $operation, AccountMergeRefusal $refusal): string
    {
        if (!in_array($refusal, [AccountMergeRefusal::Missing, AccountMergeRefusal::SurvivorMerged,
            AccountMergeRefusal::LoserMerged, AccountMergeRefusal::BeingDeleted, AccountMergeRefusal::Occupied], true)) {
            return $refusal->template();
        }
        $userId = self::refusingUserId($operation, $refusal);

        return match ($refusal) {
            AccountMergeRefusal::Missing => sprintf($refusal->template(), $userId),
            AccountMergeRefusal::SurvivorMerged, AccountMergeRefusal::LoserMerged => sprintf(
                $refusal->template(), self::accountLabel($userId), self::targetLabel($userId),
            ),
            default => sprintf($refusal->template(), self::accountLabel($userId)),
        };
    }

    /**
     * @param int $userId Account the refusal is about
     * @return string Name and number, or the number alone
     * @throws HilosException When the people reader refuses the lookup
     */
    public static function accountLabel(int $userId): string
    {
        $name = isset(Hilos::$db->users[$userId]) ? Hilos::$db->users[$userId]->name : null;
        $number = '#' . $userId;
        if ($name === null || $name === '') {
            return $number;
        }

        return $name . ' (' . $number . ')';
    }

    /**
     * @param int $userId Folded account whose chain is followed
     * @return string Live account label, or the existing words for a chain without a live end
     * @throws HilosException When the merge chain or people reader refuses the lookup
     */
    public static function targetLabel(int $userId): string
    {
        $liveUserId = Hilos::$db->userMerges->liveSurvivorOf($userId);
        if ($liveUserId === null) {
            return AbstractSessionsLibraryAgent::ACCOUNT_MERGE_ANOTHER_ACCOUNT;
        }

        return self::accountLabel($liveUserId);
    }

    /**
     * @param AccountOperation $operation Saved merge
     * @param AccountMergeRefusal $refusal Selected parameterized refusal
     * @return int Exact refusing side, survivor first for equal codes
     * @throws Throwable When the operation, preparation coordinates or payload are invalid or inaccessible
     */
    private static function refusingUserId(AccountOperation $operation, AccountMergeRefusal $refusal): int
    {
        if ($operation->kind !== AccountOperationKind::Merge || $operation->survivorUserId === null
            || $operation->loserUserId === null || $operation->survivorUserId <= 0 || $operation->loserUserId <= 0
            || $operation->survivorUserId === $operation->loserUserId) {
            throw new InvalidFormatException('A merge refusal requires its saved distinct account pair.');
        }
        foreach ([AccountMergeDuty::PrepareSurvivor, AccountMergeDuty::PrepareLoser] as $duty) {
            $userId = $duty === AccountMergeDuty::PrepareSurvivor ? $operation->survivorUserId : $operation->loserUserId;
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
                $operation->id, AccountMergeStage::Preparing->value, $duty->value,
            );
            if ($assignment === null) {
                continue;
            }
            $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);
            if ($assignment->operationId !== $operation->id || $assignment->stage !== AccountMergeStage::Preparing->value
                || $assignment->assignmentKey !== $duty->value || $assignment->userId !== $userId || !$assignment->owner->equals($owner)
                || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
                throw new InvalidFormatException('A merge refusal requires its canonical preparation assignment.');
            }
            $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
            if ($result === null) {
                continue;
            }
            if ($result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
                throw new InvalidFormatException('A merge refusal requires its exact preparation result.');
            }
            if (AccountMergePreparationResult::forAccount($operation, $userId)?->refusal === $refusal) {
                return $userId;
            }
        }

        throw new InvalidFormatException('A parameterized merge refusal requires a matching saved preparation.');
    }
}
