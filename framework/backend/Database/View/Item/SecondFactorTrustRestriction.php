<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\SecondFactorTrustRestriction as ObjectSecondFactorTrustRestriction;
use Hilos\HilosException;

/**
 * SecondFactorTrustRestriction Db item - read-facing wrapper around ObjectSecondFactorTrustRestriction (HIL-1900).
 *
 * @extends DbItem<ObjectSecondFactorTrustRestriction>
 * @property-read ?int $id
 * @property-read int $savedAt
 * @property-read int $termDays
 */
class SecondFactorTrustRestriction extends DbItem
{
    /**
     * Magic getter for restriction properties.
     *
     * @param string $name Property name (id, savedAt, termDays)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectSecondFactorTrustRestriction::id => $this->_object->id,
            ObjectSecondFactorTrustRestriction::savedAt => $this->_object->savedAt,
            ObjectSecondFactorTrustRestriction::termDays => $this->_object->termDays,
            default => parent::__get($name),
        };
    }
}
