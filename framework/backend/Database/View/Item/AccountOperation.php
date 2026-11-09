<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\AccountOperation as ObjectAccountOperation;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Database\Actions\Item\AccountOperationActions;
use Hilos\Hilos;

/**
 * @extends DbItem<ObjectAccountOperation>
 * @method __construct(ObjectAccountOperation $object)
 * @property-read ?int $id
 * @property-read AccountOperationKind $kind
 * @property-read ?int $requestId
 * @property-read ?int $loserUserId
 * @property-read ?int $survivorUserId
 * @property-read AccountMergeChoice $mergeChoice
 * @property-read int $rootUserId
 * @property-read string $circleUserIds
 * @property-read string $stage
 * @property-read int $createdAt
 * @property-read int $updatedAt
 * @property-read ?int $finishedAt
 * @property-read ?string $outcome
 * @property-read AccountOperationCircle $circle
 * @property-read AccountOperationActions $actions
 */
class AccountOperation extends DbItem
{
    public const string circle = 'circle';

    /**
     * @param string $name Stored field or direct relation
     * @return mixed Stored value or typed relation
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions are unavailable
     * @throws HilosException When the underlying reader refuses the value
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectAccountOperation::id => $this->_object->id,
            ObjectAccountOperation::kind => $this->_object->kind,
            ObjectAccountOperation::loserUserId => $this->_object->loserUserId,
            ObjectAccountOperation::survivorUserId => $this->_object->survivorUserId,
            ObjectAccountOperation::mergeChoice => $this->_object->mergeChoice(),
            ObjectAccountOperation::requestId => $this->_object->requestId,
            ObjectAccountOperation::rootUserId => $this->_object->rootUserId,
            ObjectAccountOperation::circleUserIds => $this->_object->circleUserIds,
            ObjectAccountOperation::stage => $this->_object->stage,
            ObjectAccountOperation::createdAt => $this->_object->createdAt,
            ObjectAccountOperation::updatedAt => $this->_object->updatedAt,
            ObjectAccountOperation::finishedAt => $this->_object->finishedAt,
            ObjectAccountOperation::outcome => $this->_object->outcome,
            self::circle => $this->_object->circle(),
            default => parent::__get($name),
        };
    }

    /**
     * @return ?AccountOperationResult Loser's give result, or absence before the merge fact or for an erasure
     * @throws HilosException When the assignment or result reader refuses the lookup
     */
    public function mergeFact(): ?AccountOperationResult
    {
        if ($this->_object->kind !== AccountOperationKind::Merge) {
            return null;
        }
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity(
            $this->_object->id,
            AccountMergeStage::Go->value,
            AccountMergeDuty::Give->value,
        );

        return $assignment === null ? null : Hilos::$db->accountOperationResults->byAssignment($assignment->id);
    }
}
