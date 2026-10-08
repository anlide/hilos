<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\I18nReflowActions;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\I18nReflow as ObjectI18nReflow;
use Hilos\HilosException;

/**
 * Read-facing record of the catalog fingerprint last taken into the reference tables.
 *
 * @extends DbItem<ObjectI18nReflow>
 * @property-read I18nReflowActions $actions
 * @property-read int $id
 * @property-read string $fingerprint
 */
class I18nReflow extends DbItem
{
    /**
     * @param string $name Scalar property or actions name
     * @return mixed Field value or inherited item member
     * @throws PropertyNotFoundException When the property is unknown
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectI18nReflow::id => $this->_object->id,
            ObjectI18nReflow::fingerprint => $this->_object->fingerprint,
            default => parent::__get($name),
        };
    }
}
