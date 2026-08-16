<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\RegistrationWait as ObjectRegistrationWait;
use Hilos\HilosException;

/**
 * RegistrationWait Db item - read-only wrapper around ObjectRegistrationWait.
 *
 * The unfinished-registration memory is backend-only; this wrapper exists so the
 * collection has a DbItem representation.
 *
 * @extends DbItem<ObjectRegistrationWait>
 * @property-read ?int $id
 * @property-read string $sessionToken
 * @property-read string $identifier
 */
final class RegistrationWait extends DbItem
{
    /**
     * Magic getter for wait properties.
     *
     * @param string $name Property name (id, sessionToken, identifier)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectRegistrationWait::id => $this->_object->id,
            ObjectRegistrationWait::sessionToken => $this->_object->sessionToken,
            ObjectRegistrationWait::identifier => $this->_object->identifier,
            default => parent::__get($name),
        };
    }
}
