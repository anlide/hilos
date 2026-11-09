<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\AccountOperationAssignment as ObjectAccountOperationAssignment;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Hilos;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;

/**
 * @extends DbItem<ObjectAccountOperationAssignment>
 * @method __construct(ObjectAccountOperationAssignment $object)
 * @property-read ?int $id
 * @property-read int $operationId
 * @property-read string $stage
 * @property-read string $assignmentKey
 * @property-read string $ownerType
 * @property-read ?string $ownerIndex
 * @property-read ?int $userId
 * @property-read string $payloadJson
 * @property-read int $createdAt
 * @property-read ?AccountOperation $operation
 * @property-read AccountOperationOwnerAddress $owner
 * @property-read AccountOperationAssignmentPayload $payload
 */
class AccountOperationAssignment extends DbItem
{
    public const string operation = 'operation';
    public const string owner = 'owner';
    public const string payload = 'payload';

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
            ObjectAccountOperationAssignment::id => $this->_object->id,
            ObjectAccountOperationAssignment::operationId => $this->_object->operationId,
            ObjectAccountOperationAssignment::stage => $this->_object->stage,
            ObjectAccountOperationAssignment::assignmentKey => $this->_object->assignmentKey,
            ObjectAccountOperationAssignment::ownerType => $this->_object->ownerType,
            ObjectAccountOperationAssignment::ownerIndex => $this->_object->ownerIndex,
            ObjectAccountOperationAssignment::userId => $this->_object->userId,
            ObjectAccountOperationAssignment::payloadJson => $this->_object->payloadJson,
            ObjectAccountOperationAssignment::createdAt => $this->_object->createdAt,
            self::operation => Hilos::$db->accountOperations[$this->_object->operationId] ?? null,
            self::owner => new AccountOperationOwnerAddress($this->_object->ownerType, $this->_object->ownerIndex),
            self::payload => AccountOperationAssignmentPayload::fromJson($this->_object->payloadJson),
            default => parent::__get($name),
        };
    }
}
