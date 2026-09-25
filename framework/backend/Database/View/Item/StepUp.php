<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\StepUp as ObjectStepUp;
use Hilos\HilosException;

/**
 * StepUp Db item - read-facing wrapper around ObjectStepUp (HIL-495).
 *
 * @extends DbItem<ObjectStepUp>
 * @property-read ?int $id
 * @property-read string $sessionTokenHash
 * @property-read int $userId
 * @property-read string $operation
 * @property-read string $confirmedUntil
 * @property-read string $createdAt
 */
final class StepUp extends DbItem
{
    /**
     * @param string $name Property name (see class @property list)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If an item actions class is invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectStepUp::id => $this->_object->id,
            ObjectStepUp::sessionTokenHash => $this->_object->sessionTokenHash,
            ObjectStepUp::userId => $this->_object->userId,
            ObjectStepUp::operation => $this->_object->operation,
            ObjectStepUp::confirmedUntil => $this->_object->confirmedUntil,
            ObjectStepUp::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }
}
