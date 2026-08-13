<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;

/**
 * RegistrationReservation Db item - read-only wrapper around ObjectRegistrationReservation.
 *
 * The reservation layer is backend-only; this wrapper exists so the collection
 * has a DbItem representation. The credential hash is never exposed here.
 *
 * @extends DbItem<ObjectRegistrationReservation>
 * @property-read ?int $id
 * @property-read string $type
 * @property-read string $identifier
 * @property-read string $expiresAt
 */
final class RegistrationReservation extends DbItem
{
    /**
     * Magic getter for reservation properties.
     *
     * @param string $name Property name (id, type, identifier, expiresAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectRegistrationReservation::id => $this->_object->id,
            ObjectRegistrationReservation::type => $this->_object->type,
            ObjectRegistrationReservation::identifier => $this->_object->identifier,
            ObjectRegistrationReservation::expiresAt => $this->_object->expiresAt,
            default => parent::__get($name),
        };
    }
}
