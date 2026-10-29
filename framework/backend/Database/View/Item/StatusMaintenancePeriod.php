<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\StatusMaintenancePeriodActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\StatusMaintenancePeriod as ObjectStatusMaintenancePeriod;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;

/**
 * @extends DbItem<ObjectStatusMaintenancePeriod>
 * @method __construct(ObjectStatusMaintenancePeriod $object)
 * @property-read ?int $id Stored id
 * @property-read string $publicId Stored public_id
 * @property-read ?int $workId Stored work_id
 * @property-read ?string $workPublicId Stored work_public_id
 * @property-read bool $announcementKnown Stored announcement_known
 * @property-read string $title Stored title
 * @property-read string $componentKeysJson Stored component_keys_json
 * @property-read int $startedAt Stored started_at
 * @property-read int $lastObservedAt Stored last_observed_at
 * @property-read ?int $endedAt Stored ended_at
 * @property-read ?int $completionObservedAt Stored completion_observed_at
 * @property-read ?StatusWork $work Original persisted work, if its public identity still matches
 * @property-read list<string> $componentKeys Detached historical key snapshot
 * @property-read StatusMaintenancePeriodActions $actions Storage mutations
 */
class StatusMaintenancePeriod extends DbItem
{
    public const string work = 'work';
    public const string componentKeys = 'componentKeys';

    public const string id = 'id';
    public const string publicId = 'publicId';
    public const string workId = 'workId';
    public const string workPublicId = 'workPublicId';
    public const string announcementKnown = 'announcementKnown';
    public const string title = 'title';
    public const string componentKeysJson = 'componentKeysJson';
    public const string startedAt = 'startedAt';
    public const string lastObservedAt = 'lastObservedAt';
    public const string endedAt = 'endedAt';
    public const string completionObservedAt = 'completionObservedAt';

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
            self::workId => $this->_object->work_id,
            self::workPublicId => $this->_object->work_public_id,
            self::announcementKnown => $this->_object->announcement_known,
            self::title => $this->_object->title,
            self::componentKeysJson => $this->_object->component_keys_json,
            self::startedAt => $this->_object->started_at,
            self::lastObservedAt => $this->_object->last_observed_at,
            self::endedAt => $this->_object->ended_at,
            self::completionObservedAt => $this->_object->completion_observed_at,
            self::work => isset(Hilos::$db->statusWorks[$this->_object->work_id])
                && Hilos::$db->statusWorks[$this->_object->work_id]->publicId === $this->_object->work_public_id
                    ? Hilos::$db->statusWorks[$this->_object->work_id] : null,
            self::componentKeys => $this->decodeComponentKeys(),
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
            self::publicId => $this->_object->public_id,
            self::workId => $this->_object->work_id,
            self::workPublicId => $this->_object->work_public_id,
            self::announcementKnown => $this->_object->announcement_known,
            self::title => $this->_object->title,
            self::componentKeysJson => $this->_object->component_keys_json,
            self::startedAt => $this->_object->started_at,
            self::lastObservedAt => $this->_object->last_observed_at,
            self::endedAt => $this->_object->ended_at,
            self::completionObservedAt => $this->_object->completion_observed_at,
        ];
        if (!$withId && !$toFrontend) {
            unset($fields[self::id]);
        }
        return $fields;
    }

    /**
     * @return list<string> Sanitized snapshot without a live catalog relation
     * @throws DatabaseException When stored snapshot JSON is malformed
     */
    private function decodeComponentKeys(): array
    {
        try {
            $keys = json_decode($this->_object->component_keys_json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new DatabaseException('Maintenance component snapshot is malformed', previous: $failure);
        }
        if (!is_array($keys) || !array_is_list($keys)) {
            throw new DatabaseException('Maintenance component snapshot must be a list');
        }
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new DatabaseException('Maintenance component snapshot must contain string keys');
            }
        }
        sort($keys, SORT_STRING);
        return $keys;
    }
}
