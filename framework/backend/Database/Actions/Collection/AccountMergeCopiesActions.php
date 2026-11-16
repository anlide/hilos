<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AccountMergeCopies as ObjectAccountMergeCopies;
use Hilos\Database\View\Collection\AccountMergeCopies as DbCollectionAccountMergeCopies;
use Hilos\Database\View\Item\AccountMergeCopy;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\HilosException;
use JsonException;

/**
 * @extends DbActions<AccountMergeCopy, ObjectAccountMergeCopies>
 * @property-read DbCollectionAccountMergeCopies $collection
 * @property-read ObjectAccountMergeCopies $objectCollection
 */
class AccountMergeCopiesActions extends DbActions
{
    /**
     * Joins the participant transaction before that step records its nonsecret give result.
     *
     * @param AccountOperationAssignment $assignment Canonical loser's go/give assignment
     * @param AccountMergeHandoverCopy $copy Complete private transfer copy
     * @return AccountMergeCopy Newly completed immutable copy
     * @throws HilosException When the transaction, assignment, Add right or storage refuses creation
     * @throws JsonException When the private copy cannot be encoded
     */
    public function record(AccountOperationAssignment $assignment, AccountMergeHandoverCopy $copy): AccountMergeCopy
    {
        if (Database::runtimeTransactionLevel($this->objectCollection->getDatabase()->connectionIndex) === null) {
            throw new DatabaseException('A merge copy must be recorded inside its participant transaction');
        }
        $operation = $assignment->operation;
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->stage !== AccountMergeStage::Go->value || $operation->finishedAt !== null
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Give->value
            || $assignment->userId !== $operation->loserUserId
            || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId))
            || ExecutionContext::currentAgentId() !== $assignment->owner->agentId()
            || $copy->assignmentId !== $assignment->id || $copy->operationId !== $operation->id
            || $copy->loserUserId !== $operation->loserUserId || $copy->survivorUserId !== $operation->survivorUserId) {
            throw new ValidationException('A merge copy requires its canonical loser give assignment and current owner.');
        }
        $this->ensureCanCreateInSet($assignment->owner->agentId());

        return $this->createDbItemFromObject($this->objectCollection->record($assignment->id, $assignment->owner->agentId(), $copy));
    }
}
