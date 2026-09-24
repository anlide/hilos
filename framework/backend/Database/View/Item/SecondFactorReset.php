<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\SecondFactorResetActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\SecondFactorReset as ObjectSecondFactorReset;
use Hilos\HilosException;

/**
 * SecondFactorReset Db item - read-facing wrapper around ObjectSecondFactorReset (HIL-494).
 *
 * A delayed removal of a person's second factor. The hash of the cancel token is not a
 * property: the only question asked of it is which request a link names, and the
 * collection answers that.
 *
 * @extends DbItem<ObjectSecondFactorReset>
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $requestedAt
 * @property-read string $effectiveAt
 * @property-read string $notifiedAt
 * @property-read ?string $canceledAt
 * @property-read ?string $completedAt
 * @property-read SecondFactorResetActions $actions
 */
final class SecondFactorReset extends DbItem
{
    /**
     * Magic getter for request properties.
     *
     * @param string $name Property name (id, userId, requestedAt, effectiveAt, notifiedAt, canceledAt, completedAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectSecondFactorReset::id => $this->_object->id,
            ObjectSecondFactorReset::userId => $this->_object->userId,
            ObjectSecondFactorReset::requestedAt => $this->_object->requestedAt,
            ObjectSecondFactorReset::effectiveAt => $this->_object->effectiveAt,
            ObjectSecondFactorReset::notifiedAt => $this->_object->notifiedAt,
            ObjectSecondFactorReset::canceledAt => $this->_object->canceledAt,
            ObjectSecondFactorReset::completedAt => $this->_object->completedAt,
            default => parent::__get($name),
        };
    }

    /**
     * Whether the request still stands - neither canceled nor carried out.
     *
     * @return bool True while the request stands
     */
    public function isLive(): bool
    {
        return $this->_object->isLive();
    }
}
