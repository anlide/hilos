<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\SecondFactorActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\SecondFactor as ObjectSecondFactor;
use Hilos\HilosException;

/**
 * SecondFactor Db item - read-facing wrapper around ObjectSecondFactor (HIL-494).
 *
 * One authenticator app of a person. The shared secret is not a property: it is read
 * through {@see readSecret()} by the code check and by the answer that starts an
 * enrolment, and by nothing else.
 *
 * @extends DbItem<ObjectSecondFactor>
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $label
 * @property-read ?int $lastUsedStep
 * @property-read ?string $confirmedAt
 * @property-read ?string $lastUsedAt
 * @property-read string $createdAt
 * @property-read SecondFactorActions $actions
 */
final class SecondFactor extends DbItem
{
    /**
     * Magic getter for authenticator properties.
     *
     * @param string $name Property name (id, userId, label, lastUsedStep, confirmedAt, lastUsedAt, createdAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectSecondFactor::id => $this->_object->id,
            ObjectSecondFactor::userId => $this->_object->userId,
            ObjectSecondFactor::label => $this->_object->label,
            ObjectSecondFactor::lastUsedStep => $this->_object->lastUsedStep,
            ObjectSecondFactor::confirmedAt => $this->_object->confirmedAt,
            ObjectSecondFactor::lastUsedAt => $this->_object->lastUsedAt,
            ObjectSecondFactor::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }

    /**
     * Reads the stored shared secret.
     *
     * @return ?string Base32 secret, or null when none is stored
     * @throws DatabaseException When the secret lookup query fails
     */
    public function readSecret(): ?string
    {
        return $this->_object->readSecret();
    }
}
