<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\User as FrameworkUser;

/**
 * User - Object wrapper for user entity.
 *
 * Business logic layer with change tracking.
 *
 * @property ?int $mergedInto
 */
final class User extends FrameworkUser
{
    public const string ENTITY_CLASS = EntityUser::class;

    public const string mergedInto = 'mergedInto';

    /**
     * Returns the value of a user object property by name.
     *
     * @param string $property Property name (mergedInto or a framework field)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::mergedInto => $this->entity->merged_into,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a user object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException If entity sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::mergedInto => $this->entity->merged_into = $value === null ? null : (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the user object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            self::mergedInto => $this->entity->merged_into,
        ];
    }
}
