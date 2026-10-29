<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\StatusWorkComponentActions;
use Hilos\Database\Object\Item\StatusWorkComponent as ObjectStatusWorkComponent;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectStatusWorkComponent>
 * @method __construct(ObjectStatusWorkComponent $object)
 * @property-read ?int $id Stored id
 * @property-read int $workId Stored work_id
 * @property-read string $componentKey Stored component_key
 * @property-read StatusWorkComponentActions $actions Storage mutations
 */
class StatusWorkComponent extends DbItem
{
    public const string id = 'id';
    public const string workId = 'workId';
    public const string componentKey = 'componentKey';

    /**
     * @param string $name View field or inherited property
     * @return mixed Scalar or inherited value
     * @throws HilosException When the inherited property cannot be read
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::id => $this->_object->id,
            self::workId => $this->_object->work_id,
            self::componentKey => $this->_object->component_key,
            default => parent::__get($name),
        };
    }

    /**
     * @param bool $withId Include the primary id
     * @param bool $idAsIndex Reserved base flag
     * @param bool $withBridges Reserved base flag; relations remain access-only
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
            self::workId => $this->_object->work_id,
            self::componentKey => $this->_object->component_key,
        ];
        if (!$withId && !$toFrontend) {
            unset($fields[self::id]);
        }
        return $fields;
    }
}
