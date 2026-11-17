<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\Object\Item\AccountMergeCopy as ObjectAccountMergeCopy;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;

/**
 * Only metadata crosses the ordinary ORM boundary. The guarded reader returns a fresh private copy.
 *
 * @extends DbItem<ObjectAccountMergeCopy>
 * @method __construct(ObjectAccountMergeCopy $object)
 * @property-read int $assignmentId
 * @property-read string $ownerAgentId
 * @property-read int $createdAt
 */
class AccountMergeCopy extends DbItem
{
    /**
     * @param string $name Stored metadata field
     * @return mixed Scalar metadata or a base item property
     * @throws HilosException When the requested property is unavailable
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectAccountMergeCopy::assignmentId => $this->_object->assignmentId,
            ObjectAccountMergeCopy::ownerAgentId => $this->_object->ownerAgentId,
            ObjectAccountMergeCopy::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }

    /**
     * @return AccountMergeHandoverCopy Private copy backed by its exact committed give fact
     * @throws HilosException When the owner, assignment, result or private storage refuses the read
     */
    public function readForMerge(): AccountMergeHandoverCopy
    {
        $assignment = Hilos::$db->accountOperationAssignments[$this->_object->assignmentId] ?? null;
        $operation = $assignment?->operation;
        if ($assignment === null || $operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->loserUserId === null || $operation->survivorUserId === null
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Give->value
            || $assignment->userId !== $operation->loserUserId
            || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId))
            || $this->_object->ownerAgentId !== $assignment->owner->agentId()
            || ExecutionContext::currentAgentId() !== $this->_object->ownerAgentId) {
            throw new ValidationException('A merge copy can only be read by its canonical loser give owner.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null || $result->assignmentId !== $assignment->id || $result->ownerAgentId !== $this->_object->ownerAgentId) {
            throw new ValidationException('A merge copy requires its exact saved give result.');
        }
        $give = AccountMergeGiveResult::of($result->payload);
        $copy = $this->_object->readPayload();
        if ($copy->assignmentId !== $assignment->id || $copy->operationId !== $operation->id
            || $copy->loserUserId !== $operation->loserUserId || $copy->survivorUserId !== $operation->survivorUserId
            || $copy->effectiveWaitDays !== $give->effectiveWaitDays
            || ($operation->mergeChoice->secondFactorFate === SecondFactorFate::BOTH) !== ($give->effectiveWaitDays !== null)) {
            throw new ValidationException('The saved merge copy does not match its assignment.');
        }
        foreach ([AccountMergeHandoverCopy::identities, AccountMergeHandoverCopy::passkeyCredentials,
            AccountMergeHandoverCopy::secondFactors, AccountMergeHandoverCopy::secondFactorBackupCodes] as $rows) {
            $ids = [];
            foreach ($copy->{$rows} as $row) {
                $ids[] = $row->id;
            }
            if ($give->{$rows} !== $ids) {
                throw new ValidationException('The saved merge copy does not match its give manifest.');
            }
        }

        return $copy;
    }
}
