<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Throwable;

/**
 * Durable cancel-before-fact driver; records obligations and waits for both owners without sending frames.
 * The prepared coordinator checks strict release bindings before advancing cancellation.
 * SCAFFOLD: production merge switches in HIL-1999.
 */
final class AccountMergeCancellation
{
    /**
     * @param AccountOperation $operation Merge whose cancellation is requested
     * @return AccountMergeCancellationState Durable cancellation progress
     * @throws Throwable When ownership, validation or persistence fails; stage and obligations roll back together
     */
    public static function request(AccountOperation $operation): AccountMergeCancellationState
    {
        return Database::transaction(DatabaseNames::PRIMARY, static function () use ($operation): AccountMergeCancellationState {
            if (!$operation->actions->beginMergeCancellation()) {
                return $operation->outcome === AccountOperationOutcome::NotStarted->value
                    ? AccountMergeCancellationState::NotStarted : AccountMergeCancellationState::TooLate;
            }
            foreach ([
                [$operation->loserUserId, AccountMergeDuty::ReleaseLoser],
                [$operation->survivorUserId, AccountMergeDuty::ReleaseSurvivor],
            ] as [$userId, $duty]) {
                Hilos::$db->accountOperationAssignments->actions->record(
                    $operation->id, AccountMergeStage::Cancelling->value, $duty->value,
                    new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
                    $userId, AccountOperationAssignmentPayload::fromJson('{}'),
                );
            }

            return AccountMergeCancellationState::WaitingOwners;
        });
    }

    /**
     * @param AccountOperation $operation Merge already asked to cancel
     * @return AccountMergeCancellationState Waiting, closed without starting, or past the give fact
     * @throws Throwable When validation, ownership or persistence refuses progress; a failed finish rolls back
     */
    public static function advance(AccountOperation $operation): AccountMergeCancellationState
    {
        return Database::transaction(DatabaseNames::PRIMARY, static function () use ($operation): AccountMergeCancellationState {
            $current = Hilos::$db->accountOperations->fresh($operation->id);
            if ($current === null || $current->kind !== AccountOperationKind::Merge) {
                throw new ValidationException('Merge cancellation requires an existing merge operation.');
            }
            if ($current->finishedAt !== null) {
                return $current->outcome === AccountOperationOutcome::NotStarted->value
                    ? AccountMergeCancellationState::NotStarted : AccountMergeCancellationState::TooLate;
            }
            if ($current->mergeFact() !== null) {
                return AccountMergeCancellationState::TooLate;
            }
            if ($current->stage !== AccountMergeStage::Cancelling->value) {
                throw new ValidationException('The merge has not requested cancellation.');
            }
            $loserReleased = AccountMergeReleaseResult::forAccount($current, $current->loserUserId);
            $survivorReleased = AccountMergeReleaseResult::forAccount($current, $current->survivorUserId);
            if (!$loserReleased || !$survivorReleased) {
                return AccountMergeCancellationState::WaitingOwners;
            }
            if ($current->actions->finish(AccountMergeStage::Cancelling->value, AccountOperationOutcome::NotStarted)
                || $current->outcome === AccountOperationOutcome::NotStarted->value) {
                return AccountMergeCancellationState::NotStarted;
            }

            return AccountMergeCancellationState::TooLate;
        });
    }
}
