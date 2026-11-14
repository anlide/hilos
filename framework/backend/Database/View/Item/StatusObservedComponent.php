<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\StatusObservedComponentActions;
use Hilos\Database\Object\Item\StatusObservedComponent as ObjectStatusObservedComponent;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectStatusObservedComponent>
 * @method __construct(ObjectStatusObservedComponent $object)
 * @property-read ?int $id Internal primary id
 * @property-read string $componentKey Status component key
 * @property-read int $observedSince Start of observation in signed UTC epoch milliseconds
 * @property-read StatusObservedComponentActions $actions No item writes: the row never changes
 */
class StatusObservedComponent extends DbItem
{
    public const string id = 'id';
    public const string componentKey = 'componentKey';
    public const string observedSince = 'observedSince';

    /**
     * @param string $name View field or inherited property
     * @return mixed Scalar or inherited value
     * @throws HilosException When the inherited property cannot be read
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::id => $this->_object->id,
            self::componentKey => $this->_object->component_key,
            self::observedSince => $this->_object->observed_since,
            default => parent::__get($name),
        };
    }

    /**
     * @param bool $withId Include the internal primary id
     * @param bool $idAsIndex Reserved base flag
     * @param bool $withBridges Reserved base flag
     * @param bool $withCalculation Reserved base flag
     * @param bool $toFrontend Include the primary id regardless of withId
     * @return array<string, mixed> Scalar camelCase fields
     */
    public function toArray(
        bool $withId = true,
        bool $idAsIndex = true,
        bool $withBridges = false,
        bool $withCalculation = false,
        bool $toFrontend = false,
    ): array {
        $fields = [
            self::id => $this->_object->id,
            self::componentKey => $this->_object->component_key,
            self::observedSince => $this->_object->observed_since,
        ];
        if (!$withId && !$toFrontend) {
            unset($fields[self::id]);
        }
        return $fields;
    }
}
