<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\AccountErasureOperation as ObjectAccountErasureOperation;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Auth\AccountErasure\AccountErasureCircle;
use Hilos\Database\Actions\Item\AccountErasureOperationActions;

/**
 * @extends DbItem<ObjectAccountErasureOperation>
 * @method __construct(ObjectAccountErasureOperation $object)
 * @property-read ?int $id
 * @property-read int $requestId
 * @property-read int $rootUserId
 * @property-read string $circleUserIds
 * @property-read string $stage
 * @property-read int $createdAt
 * @property-read int $updatedAt
 * @property-read ?int $finishedAt
 * @property-read ?string $outcome
 * @property-read AccountErasureCircle $circle
 * @property-read AccountErasureOperationActions $actions
 */
class AccountErasureOperation extends DbItem
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
            ObjectAccountErasureOperation::id => $this->_object->id,
            ObjectAccountErasureOperation::requestId => $this->_object->requestId,
            ObjectAccountErasureOperation::rootUserId => $this->_object->rootUserId,
            ObjectAccountErasureOperation::circleUserIds => $this->_object->circleUserIds,
            ObjectAccountErasureOperation::stage => $this->_object->stage,
            ObjectAccountErasureOperation::createdAt => $this->_object->createdAt,
            ObjectAccountErasureOperation::updatedAt => $this->_object->updatedAt,
            ObjectAccountErasureOperation::finishedAt => $this->_object->finishedAt,
            ObjectAccountErasureOperation::outcome => $this->_object->outcome,
            self::circle => $this->_object->circle(),
            default => parent::__get($name),
        };
    }
}
