<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\AccountDeletionActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\HilosException;

/**
 * AccountDeletion Db item - read-facing wrapper around ObjectAccountDeletion (HIL-302).
 *
 * A person's own request to delete their account.
 *
 * @extends DbItem<ObjectAccountDeletion>
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $requestedAt
 * @property-read string $effectiveAt
 * @property-read ?string $canceledAt
 * @property-read ?string $completedAt
 * @property-read AccountDeletionActions $actions
 */
final class AccountDeletion extends DbItem
{
    /**
     * Magic getter for request properties.
     *
     * @param string $name Property name (id, userId, requestedAt, effectiveAt, canceledAt, completedAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectAccountDeletion::id => $this->_object->id,
            ObjectAccountDeletion::userId => $this->_object->userId,
            ObjectAccountDeletion::requestedAt => $this->_object->requestedAt,
            ObjectAccountDeletion::effectiveAt => $this->_object->effectiveAt,
            ObjectAccountDeletion::canceledAt => $this->_object->canceledAt,
            ObjectAccountDeletion::completedAt => $this->_object->completedAt,
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
