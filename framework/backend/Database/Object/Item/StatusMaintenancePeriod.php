<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusMaintenancePeriod as EntityStatusMaintenancePeriod;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Collection\StatusMaintenancePeriods as ObjectStatusMaintenancePeriods;

/**
 * @extends Object_<EntityStatusMaintenancePeriod>
 * @property ?int $id Scalar storage field
 * @property string $public_id Scalar storage field
 * @property ?int $work_id Scalar storage field
 * @property ?string $work_public_id Scalar storage field
 * @property bool $announcement_known Scalar storage field
 * @property string $title Scalar storage field
 * @property string $component_keys_json Scalar storage field
 * @property int $started_at Scalar storage field
 * @property int $last_observed_at Scalar storage field
 * @property ?int $ended_at Scalar storage field
 * @property ?int $completion_observed_at Scalar storage field
 */
class StatusMaintenancePeriod extends Object_
{
    public const string ENTITY_CLASS = EntityStatusMaintenancePeriod::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusMaintenancePeriods::class;

    public const string id = 'id';
    public const string public_id = 'public_id';
    public const string work_id = 'work_id';
    public const string work_public_id = 'work_public_id';
    public const string announcement_known = 'announcement_known';
    public const string title = 'title';
    public const string component_keys_json = 'component_keys_json';
    public const string started_at = 'started_at';
    public const string last_observed_at = 'last_observed_at';
    public const string ended_at = 'ended_at';
    public const string completion_observed_at = 'completion_observed_at';

    /**
     * @param string $property Scalar storage field
     * @return mixed Stored value or inherited property
     * @throws DatabaseException When the property is unknown
     * @throws PropertyNotAccessibleException When the inherited property is inaccessible
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::public_id => $this->entity->public_id,
            self::work_id => $this->entity->work_id,
            self::work_public_id => $this->entity->work_public_id,
            self::announcement_known => $this->entity->announcement_known,
            self::title => $this->entity->title,
            self::component_keys_json => $this->entity->component_keys_json,
            self::started_at => $this->entity->started_at,
            self::last_observed_at => $this->entity->last_observed_at,
            self::ended_at => $this->entity->ended_at,
            self::completion_observed_at => $this->entity->completion_observed_at,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Scalar storage field
     * @param mixed $value Value with the declared field type
     * @throws DatabaseException When the property is not writable
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::public_id => $this->entity->public_id = $value,
            self::work_id => $this->entity->work_id = $value,
            self::work_public_id => $this->entity->work_public_id = $value,
            self::announcement_known => $this->entity->announcement_known = $value,
            self::title => $this->entity->title = $value,
            self::component_keys_json => $this->entity->component_keys_json = $value,
            self::started_at => $this->entity->started_at = $value,
            self::last_observed_at => $this->entity->last_observed_at = $value,
            self::ended_at => $this->entity->ended_at = $value,
            self::completion_observed_at => $this->entity->completion_observed_at = $value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar storage row */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::public_id => $this->entity->public_id,
            self::work_id => $this->entity->work_id,
            self::work_public_id => $this->entity->work_public_id,
            self::announcement_known => $this->entity->announcement_known,
            self::title => $this->entity->title,
            self::component_keys_json => $this->entity->component_keys_json,
            self::started_at => $this->entity->started_at,
            self::last_observed_at => $this->entity->last_observed_at,
            self::ended_at => $this->entity->ended_at,
            self::completion_observed_at => $this->entity->completion_observed_at,
        ];
    }

    /**
     * @param int $startedAt First occupied fact in UTC epoch milliseconds
     * @param int $lastObservedAt Last occupied fact in UTC epoch milliseconds
     * @param ?int $endedAt Exact continuous end, null when unknown or still open
     * @param ?int $completionObservedAt Proven completion observation, null when still open
     * @throws ValidationException When the observation would invent a time ordering or an exact end
     */
    public static function validateObservation(int $startedAt, int $lastObservedAt, ?int $endedAt, ?int $completionObservedAt): void
    {
        if ($startedAt < 0 || $lastObservedAt < $startedAt
            || ($completionObservedAt !== null && $completionObservedAt < $lastObservedAt)
            || ($endedAt !== null && ($completionObservedAt === null || $endedAt !== $completionObservedAt))) {
            throw new ValidationException('Maintenance observations must preserve their time order.');
        }
    }
}
