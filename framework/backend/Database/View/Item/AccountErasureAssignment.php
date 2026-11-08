<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\AccountErasureAssignment as ObjectAccountErasureAssignment;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Hilos;
use Hilos\Auth\AccountErasure\AccountErasureOwnerAddress;
use Hilos\Auth\AccountErasure\AccountErasureAssignmentPayload;

/**
 * @extends DbItem<ObjectAccountErasureAssignment>
 * @method __construct(ObjectAccountErasureAssignment $object)
 * @property-read ?int $id
 * @property-read int $operationId
 * @property-read string $stage
 * @property-read string $assignmentKey
 * @property-read string $ownerType
 * @property-read ?string $ownerIndex
 * @property-read ?int $userId
 * @property-read string $payloadJson
 * @property-read int $createdAt
 * @property-read ?AccountErasureOperation $operation
 * @property-read AccountErasureOwnerAddress $owner
 * @property-read AccountErasureAssignmentPayload $payload
 */
class AccountErasureAssignment extends DbItem
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
            ObjectAccountErasureAssignment::id => $this->_object->id,
            ObjectAccountErasureAssignment::operationId => $this->_object->operationId,
            ObjectAccountErasureAssignment::stage => $this->_object->stage,
            ObjectAccountErasureAssignment::assignmentKey => $this->_object->assignmentKey,
            ObjectAccountErasureAssignment::ownerType => $this->_object->ownerType,
            ObjectAccountErasureAssignment::ownerIndex => $this->_object->ownerIndex,
            ObjectAccountErasureAssignment::userId => $this->_object->userId,
            ObjectAccountErasureAssignment::payloadJson => $this->_object->payloadJson,
            ObjectAccountErasureAssignment::createdAt => $this->_object->createdAt,
            self::operation => Hilos::$db->accountErasureOperations[$this->_object->operationId] ?? null,
            self::owner => new AccountErasureOwnerAddress($this->_object->ownerType, $this->_object->ownerIndex),
            self::payload => AccountErasureAssignmentPayload::fromJson($this->_object->payloadJson),
            default => parent::__get($name),
        };
    }
}
