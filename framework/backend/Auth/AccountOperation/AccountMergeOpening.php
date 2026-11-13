<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Throwable;

/**
 * Opens a merge with its two preparations and the project's expected composition in one
 * transaction, and reconciles an open merge by recording what the project census expects about the
 * loser and the operation does not carry yet. Neither path deletes or rewrites an assignment.
 *
 * The composition is read back with {@see AccountOperationExpectedSet::of()}: the preparations and
 * the give obligation carry no `expect:` or `wait:` key and are not part of it.
 * SCAFFOLD: no live caller until the merge coordinator opens its operation and reconciles it
 * before asking its owners (HIL-2000); the live merge stays on its existing path until the merge
 * switch (HIL-1999).
 */
final class AccountMergeOpening
{
    /**
     * A repeat for the same pair and choice records nothing new unless the census grew.
     *
     * @param int $survivorUserId Immutable survivor account id
     * @param int $loserUserId Immutable loser account id
     * @param AccountMergeChoice $choice Immutable fates and supplied protection flags
     * @return AccountOperation Committed operation with its two preparations and the project's composition, or their exact repeat
     * @throws Throwable When reading or recording fails; the operation and its assignments roll back together
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
            AccountOperationComposition::recordMissing($operation, AccountMergeProjectCensus::of($loserUserId));

            return $operation;
        });
    }

    /**
     * @param AccountOperation $operation Open merge to reconcile
     * @return list<AccountOperationAssignment> Assignments written at the operation's current stage, in census order
     * @throws ValidationException When the operation is not a merge or is already finished
     * @throws LogicException When the merge does not record its loser
     * @throws Throwable When reading or recording fails; the assignments of this call roll back together
     */
    public static function reconcile(AccountOperation $operation): array
    {
        if ($operation->kind !== AccountOperationKind::Merge || $operation->finishedAt !== null) {
            throw new ValidationException('Only an open merge can be reconciled.');
        }
        $loserUserId = $operation->loserUserId ?? throw new LogicException('An account merge must record its loser.');

        return Database::transaction(
            DatabaseNames::PRIMARY,
            static fn (): array => AccountOperationComposition::recordMissing($operation, AccountMergeProjectCensus::of($loserUserId)),
        );
    }
}
