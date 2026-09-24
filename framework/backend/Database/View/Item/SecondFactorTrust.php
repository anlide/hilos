<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\SecondFactorTrust as ObjectSecondFactorTrust;
use Hilos\HilosException;

/**
 * SecondFactorTrust Db item - read-facing wrapper around ObjectSecondFactorTrust (HIL-494).
 *
 * @extends DbItem<ObjectSecondFactorTrust>
 * @property-read ?int $id
 * @property-read int $sessionId
 * @property-read int $userId
 * @property-read string $trustedUntil
 * @property-read string $createdAt
 */
final class SecondFactorTrust extends DbItem
{
    /**
     * Magic getter for trust properties.
     *
     * @param string $name Property name (id, sessionId, userId, trustedUntil, createdAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectSecondFactorTrust::id => $this->_object->id,
            ObjectSecondFactorTrust::sessionId => $this->_object->sessionId,
            ObjectSecondFactorTrust::userId => $this->_object->userId,
            ObjectSecondFactorTrust::trustedUntil => $this->_object->trustedUntil,
            ObjectSecondFactorTrust::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }
}
