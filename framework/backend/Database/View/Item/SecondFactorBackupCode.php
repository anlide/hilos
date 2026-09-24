<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\SecondFactorBackupCodeActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\SecondFactorBackupCode as ObjectSecondFactorBackupCode;
use Hilos\HilosException;

/**
 * SecondFactorBackupCode Db item - read-facing wrapper around ObjectSecondFactorBackupCode (HIL-494).
 *
 * One backup code row; the code itself is DB-only and is not a property.
 *
 * @extends DbItem<ObjectSecondFactorBackupCode>
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read ?string $usedAt
 * @property-read string $createdAt
 * @property-read SecondFactorBackupCodeActions $actions
 */
final class SecondFactorBackupCode extends DbItem
{
    /**
     * Magic getter for backup code properties.
     *
     * @param string $name Property name (id, userId, usedAt, createdAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectSecondFactorBackupCode::id => $this->_object->id,
            ObjectSecondFactorBackupCode::userId => $this->_object->userId,
            ObjectSecondFactorBackupCode::usedAt => $this->_object->usedAt,
            ObjectSecondFactorBackupCode::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }
}
