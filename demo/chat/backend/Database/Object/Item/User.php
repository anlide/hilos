<?php

namespace Demo\Chat\Database\Object\Item;

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

    /**
     * Returns a user by session token.
     *
     * @param string $sessionToken Session token to search for
     * @return ?self User object or null if not found
     * @throws DatabaseException
     */
    public static function getBySessionToken(string $sessionToken): ?self
    {
        if (empty($sessionToken)) {
            return null;
        }

        $collection = EntityUser::get(['session_token' => $sessionToken]);
        $entity = $collection->first();
        return $entity !== null ? self::fromEntity($entity) : null;
    }

    /**
     * Registers a new user with the given session token.
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return self Registered user object
     * @throws DatabaseException On invalid token format or if user with this token already exists
     */
    public static function register(string $sessionToken): self
    {
        if (strlen($sessionToken) !== 32 || !ctype_xdigit($sessionToken)) {
            throw new DatabaseException("Invalid session token format. Expected 32 hex characters.");
        }

        $existingUser = self::getBySessionToken($sessionToken);
        if ($existingUser !== null) {
            throw new DatabaseException("User with session token already exists");
        }

        $user = self::create();
        $user->entity->name = 'User' . mt_rand(1000, 9999);
        $user->entity->session_token = $sessionToken;
        $user->entity->last_activity = date('Y-m-d H:i:s');

        try {
            $user->sync();
        } catch (DatabaseException $e) {
            throw new DatabaseException("Failed to register user: " . $e->getMessage(), $e->getCode(), $e);
        }

        return $user;
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
