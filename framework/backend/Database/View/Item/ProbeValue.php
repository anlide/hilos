<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\ProbeValueActions;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\ProbeValue as ObjectProbeValue;
use Hilos\HilosException;

/**
 * Read-facing cluster database probe row.
 *
 * @extends DbItem<ObjectProbeValue>
 * @method __construct(ObjectProbeValue $object)
 * @property-read ProbeValueActions $actions
 * @property-read ?int $id
 * @property-read string $nodeId
 * @property-read string $key
 * @property-read string $value
 */
class ProbeValue extends DbItem
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
            ObjectProbeValue::id => $this->_object->id,
            ObjectProbeValue::nodeId => $this->_object->nodeId,
            ObjectProbeValue::key => $this->_object->key,
            ObjectProbeValue::value => $this->_object->value,
            default => parent::__get($name),
        };
    }
}
