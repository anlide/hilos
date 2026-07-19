<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;

/**
 * UserVerification Db item - read-only wrapper around ObjectUserVerification.
 *
 * The verification layer is backend-only; this wrapper exists so the collection
 * has a DbItem representation. The code hash is never exposed here.
 *
 * @extends DbItem<ObjectUserVerification>
 * @property-read ?int $id
 * @property-read ?int $userId
 * @property-read string $type
 * @property-read string $identifier
 * @property-read int $attempts
 * @property-read string $expiresAt
 * @property-read ?string $consumedAt
 */
final class UserVerification extends DbItem
{
    /**
     * Magic getter for verification properties.
     *
     * @param string $name Property name (id, userId, type, identifier, attempts, expiresAt, consumedAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectUserVerification::id => $this->_object->id,
            ObjectUserVerification::userId => $this->_object->userId,
            ObjectUserVerification::type => $this->_object->type,
            ObjectUserVerification::identifier => $this->_object->identifier,
            ObjectUserVerification::attempts => $this->_object->attempts,
            ObjectUserVerification::expiresAt => $this->_object->expiresAt,
            ObjectUserVerification::consumedAt => $this->_object->consumedAt,
            default => parent::__get($name),
        };
    }
}
