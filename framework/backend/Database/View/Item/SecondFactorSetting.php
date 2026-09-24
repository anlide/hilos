<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\SecondFactorSetting as ObjectSecondFactorSetting;
use Hilos\HilosException;

/**
 * SecondFactorSetting Db item - read-facing wrapper around ObjectSecondFactorSetting (HIL-494).
 *
 * @extends DbItem<ObjectSecondFactorSetting>
 * @property-read int $userId
 * @property-read ?int $resetWaitDays
 * @property-read ?int $pendingResetWaitDays
 * @property-read ?string $pendingResetWaitFrom
 * @property-read string $updatedAt
 */
final class SecondFactorSetting extends DbItem
{
    /**
     * Magic getter for setting properties.
     *
     * @param string $name Property name (userId, resetWaitDays, pendingResetWaitDays, pendingResetWaitFrom, updatedAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectSecondFactorSetting::userId => $this->_object->userId,
            ObjectSecondFactorSetting::resetWaitDays => $this->_object->resetWaitDays,
            ObjectSecondFactorSetting::pendingResetWaitDays => $this->_object->pendingResetWaitDays,
            ObjectSecondFactorSetting::pendingResetWaitFrom => $this->_object->pendingResetWaitFrom,
            ObjectSecondFactorSetting::updatedAt => $this->_object->updatedAt,
            default => parent::__get($name),
        };
    }
}
