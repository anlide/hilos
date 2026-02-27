<?php

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * User Object
 * Auto-generated from Entity: Demo\Chat\Database\Entity\Item\User
 *
 * Business logic layer with change tracking
 *
 * @extends Object_<EntityUser>
 *
 * @property-read ?int $id
 * @property string $name
 * @property ?string $sessionToken
 * @property ?string $lastActivity
 */
final class User extends Object_
{
    public const string ENTITY_CLASS = EntityUser::class;

    public const string id = 'id';
    public const string name = 'name';
    public const string sessionToken = 'sessionToken';
    public const string lastActivity = 'lastActivity';

    protected static function getCollectionKey(): string
    {
        return DbChatContext::users;
    }

    /**
     * Returns the value of a user object property by name.
     *
     * @param string $property Property name (id, name, sessionToken, lastActivity)
     * @return mixed Property value or parent method result
     * @throws DatabaseException
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::sessionToken => $this->entity->session_token,
            self::lastActivity => $this->entity->last_activity,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a user object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::name => $this->entity->name = (string)$value,
            self::sessionToken => $this->entity->session_token = is_scalar($value) ? (string)$value : null,
            self::lastActivity => $this->entity->last_activity = is_scalar($value) ? (string)$value : null,
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
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::sessionToken => $this->entity->session_token,
            self::lastActivity => $this->entity->last_activity,
        ];
    }
}
