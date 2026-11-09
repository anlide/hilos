<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Throwable;

/** SCAFFOLD: atomic merge opening for the future coordinator; the live merge remains on its existing path. */
final class AccountMergeOpening
{
    /**
     * @param int $survivorUserId Immutable survivor account id
     * @param int $loserUserId Immutable loser account id
     * @param AccountMergeChoice $choice Immutable fates and supplied protection flags
     * @return AccountOperation Committed operation and its two initial obligations, or their exact repeat
     * @throws Throwable When recording fails; the operation and assignments roll back together
     */
    public static function open(int $survivorUserId, int $loserUserId, AccountMergeChoice $choice): AccountOperation
    {
        return Database::transaction(DatabaseNames::PRIMARY, static function () use ($survivorUserId, $loserUserId, $choice): AccountOperation {
            $operation = Hilos::$db->accountOperations->actions->recordMerge($survivorUserId, $loserUserId, $choice);
            Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id,
                AccountMergeStage::Preparing->value,
                AccountMergeDuty::PrepareSurvivor->value,
                new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$survivorUserId),
                $survivorUserId,
                AccountOperationAssignmentPayload::fromJson('{}'),
            );
            Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id,
                AccountMergeStage::Preparing->value,
                AccountMergeDuty::PrepareLoser->value,
                new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$loserUserId),
                $loserUserId,
                AccountOperationAssignmentPayload::fromJson('{}'),
            );

            return $operation;
        });
    }
}
