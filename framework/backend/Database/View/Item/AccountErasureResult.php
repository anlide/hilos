<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\AccountErasureResult as ObjectAccountErasureResult;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Hilos;
use Hilos\Auth\AccountErasure\AccountErasureResultPayload;

/**
 * @extends DbItem<ObjectAccountErasureResult>
 * @method __construct(ObjectAccountErasureResult $object)
 * @property-read ?int $id
 * @property-read int $assignmentId
 * @property-read string $ownerAgentId
 * @property-read string $payloadJson
 * @property-read int $createdAt
 * @property-read ?AccountErasureAssignment $assignment
 * @property-read AccountErasureResultPayload $payload
 */
class AccountErasureResult extends DbItem
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
            ObjectAccountErasureResult::id => $this->_object->id,
            ObjectAccountErasureResult::assignmentId => $this->_object->assignmentId,
            ObjectAccountErasureResult::ownerAgentId => $this->_object->ownerAgentId,
            ObjectAccountErasureResult::payloadJson => $this->_object->payloadJson,
            ObjectAccountErasureResult::createdAt => $this->_object->createdAt,
            self::assignment => Hilos::$db->accountErasureAssignments[$this->_object->assignmentId] ?? null,
            self::payload => AccountErasureResultPayload::fromJson($this->_object->payloadJson),
            default => parent::__get($name),
        };
    }
}
