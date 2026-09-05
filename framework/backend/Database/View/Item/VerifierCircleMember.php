<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\VerifierCircleMember as ObjectVerifierCircleMember;
use Hilos\HilosException;

/**
 * VerifierCircleMember Db item - read-facing wrapper around ObjectVerifierCircleMember.
 *
 * Surfaces the identity pair one named verifier is in the circle by (HIL-643). Whether
 * that person is signed in right now is not a field of the row: it is asked of the
 * runtime connections when a row is built, because the circle table stores who was
 * named, not who is here.
 *
 * @extends DbItem<ObjectVerifierCircleMember>
 * @property-read ?int $id
 * @property-read string $identityType
 * @property-read string $identifier
 */
final class VerifierCircleMember extends DbItem
{
    /**
     * Magic getter for verifier circle member properties.
     *
     * @param string $name Property name (id, identityType, identifier)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectVerifierCircleMember::id => $this->_object->id,
            ObjectVerifierCircleMember::identityType => $this->_object->identityType,
            ObjectVerifierCircleMember::identifier => $this->_object->identifier,
            default => parent::__get($name),
        };
    }
}
