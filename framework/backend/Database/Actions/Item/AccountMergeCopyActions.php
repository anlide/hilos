<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountMergeReceiveResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\AccountMergeCopy as ObjectAccountMergeCopy;
use Hilos\Database\View\Item\AccountMergeCopy;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;

/**
 * @extends DbActions<AccountMergeCopy, ObjectAccountMergeCopy>
 * @property-read ObjectAccountMergeCopy $object
 */
class AccountMergeCopyActions extends DbActions
{
    /**
     * Joins the participant transaction; the private payload is never read or hydrated.
     *
     * @throws HilosException When the transaction, canonical source, receipt, Remove right or storage refuses deletion
     */
    public function deleteAfterReceipt(): void
    {
        $collection = $this->getObjectCollection() ?? throw new ObjectCollectionNullException('Object collection is null');
        if (Database::runtimeTransactionLevel($collection->getDatabase()->connectionIndex) === null) {
            throw new DatabaseException('A merge copy must be removed inside its participant transaction');
        }
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $assignment = Hilos::$db->accountOperationAssignments[$this->object->assignmentId] ?? null;
        $operation = $assignment === null ? null : Hilos::$db->accountOperations->fresh($assignment->operationId);
        if ($assignment === null || $operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value
            || $operation->loserUserId === null || $operation->loserUserId <= 0
            || $operation->survivorUserId === null || $operation->survivorUserId <= 0
            || $operation->survivorUserId === $operation->loserUserId
            || $assignment->operationId !== $operation->id
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Give->value
            || $assignment->userId !== $operation->loserUserId
            || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId))
            || !$assignment->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))
            || $this->object->ownerAgentId !== $assignment->owner->agentId()
            || ExecutionContext::currentAgentId() !== $this->object->ownerAgentId) {
            throw new ValidationException('A merge copy can only be removed by its canonical source give owner during the open merge.');
        }
        $canonical = Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
        );
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($canonical === null || $canonical->id !== $assignment->id
            || $result === null || $result->assignmentId !== $assignment->id || $result->ownerAgentId !== $this->object->ownerAgentId) {
            throw new ValidationException('A merge copy requires its exact canonical give and saved result before removal.');
        }
        $give = AccountMergeGiveResult::of($result->payload);
        if (($operation->mergeChoice->secondFactorFate === SecondFactorFate::BOTH) !== ($give->effectiveWaitDays !== null)
            || AccountMergeReceiveResult::forOperation($operation) === null) {
            throw new ValidationException('A merge copy requires its exact survivor receipt before removal.');
        }
        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($collection[$idString]);
    }
}
