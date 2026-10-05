<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\UserMerge as EntityUserMerge;
use Hilos\Database\Object\Collection\UserMerges as ObjectUserMerges;

/**
 * UserMerge - Object wrapper for one row of the framework merge table.
 *
 * Business logic layer with change tracking. Keyed by the folded account.
 *
 * @extends Object_<EntityUserMerge>
 *
 * @property int $userId
 * @property ?int $survivorUserId
 * @property ?string $mergedAt
 */
class UserMerge extends Object_
{
    public const string ENTITY_CLASS = EntityUserMerge::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserMerges::class;

    public const string userId = 'userId';
    public const string survivorUserId = 'survivorUserId';
    public const string mergedAt = 'mergedAt';

    /**
     * Returns the value of a merge object property by name.
     *
     * @param string $property Property name (userId, survivorUserId, mergedAt)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::userId => $this->entity->user_id,
            self::survivorUserId => $this->entity->survivor_user_id,
            self::mergedAt => $this->entity->merged_at,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a merge object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException If entity sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::survivorUserId => $this->entity->survivor_user_id = $value === null ? null : (int)$value,
            self::mergedAt => $this->entity->merged_at = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the merge object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->entity->user_id,
            self::survivorUserId => $this->entity->survivor_user_id,
            self::mergedAt => $this->entity->merged_at,
        ];
    }
}
