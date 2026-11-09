<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\AccountOperationResult as ObjectAccountOperationResult;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Hilos;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;

/**
 * @extends DbItem<ObjectAccountOperationResult>
 * @method __construct(ObjectAccountOperationResult $object)
 * @property-read ?int $id
 * @property-read int $assignmentId
 * @property-read string $ownerAgentId
 * @property-read string $payloadJson
 * @property-read int $createdAt
 * @property-read ?AccountOperationAssignment $assignment
 * @property-read AccountOperationResultPayload $payload
 */
class AccountOperationResult extends DbItem
{
    public const string assignment = 'assignment';
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
            ObjectAccountOperationResult::id => $this->_object->id,
            ObjectAccountOperationResult::assignmentId => $this->_object->assignmentId,
            ObjectAccountOperationResult::ownerAgentId => $this->_object->ownerAgentId,
            ObjectAccountOperationResult::payloadJson => $this->_object->payloadJson,
            ObjectAccountOperationResult::createdAt => $this->_object->createdAt,
            self::assignment => Hilos::$db->accountOperationAssignments[$this->_object->assignmentId] ?? null,
            self::payload => AccountOperationResultPayload::fromJson($this->_object->payloadJson),
            default => parent::__get($name),
        };
    }
}
