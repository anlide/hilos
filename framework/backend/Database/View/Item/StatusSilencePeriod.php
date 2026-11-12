<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\StatusSilencePeriodActions;
use Hilos\Database\Object\Item\StatusSilencePeriod as ObjectStatusSilencePeriod;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectStatusSilencePeriod>
 * @method __construct(ObjectStatusSilencePeriod $object)
 * @property-read ?int $id Internal primary id
 * @property-read string $publicId Opaque publication identity
 * @property-read int $startedAt Gap start in signed UTC epoch milliseconds
 * @property-read int $endedAt Exclusive gap end in signed UTC epoch milliseconds
 * @property-read StatusSilencePeriodActions $actions Test-only archive removal
 */
class StatusSilencePeriod extends DbItem
{
    public const string id = 'id';
    public const string publicId = 'publicId';
    public const string startedAt = 'startedAt';
    public const string endedAt = 'endedAt';

    /**
     * @param string $name View field or inherited property
     * @return mixed Scalar or inherited value
     * @throws HilosException When the inherited property cannot be read
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::id => $this->_object->id,
            self::publicId => $this->_object->public_id,
            self::startedAt => $this->_object->started_at,
            self::endedAt => $this->_object->ended_at,
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
            self::publicId => $this->_object->public_id,
            self::startedAt => $this->_object->started_at,
            self::endedAt => $this->_object->ended_at,
        ];
        if (!$withId && !$toFrontend) {
            unset($fields[self::id]);
        }
        return $fields;
    }
}
