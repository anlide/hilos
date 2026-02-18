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

    // Property name constants (camelCase for PHP)
    public const string id = 'id';
    public const string name = 'name';
    public const string sessionToken = 'sessionToken';
    public const string lastActivity = 'lastActivity';

    /**
     * Load user by ID
     */
    public static function getById(int $id): ?self
    {
        $entity = EntityUser::getById($id);
        return $entity !== null ? self::fromEntity($entity) : null;
    }

    /**
     * Load user by session token
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
     * Login or register user by session token
     *
     * @param string $sessionToken Session token (32 hex characters) or empty string
     * @return self User object with valid session token
     */
    public static function loginOrRegister(string $sessionToken): self
    {
        if (!empty($sessionToken) && strlen($sessionToken) === 32) {
            $user = self::getBySessionToken($sessionToken);

            if ($user !== null) {
                $user->updateActivity();
                return $user;
            }
        }

        $user = self::create();
        $user->entity->name = 'User' . mt_rand(1000, 9999);
        $user->entity->session_token = self::generateSessionToken();
        $user->entity->last_activity = date('Y-m-d H:i:s');
        $user->sync();

        return $user;
    }

    /**
     * Register new user
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

    private static function generateSessionToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function updateActivity(): void
    {
        $this->entity->last_activity = date('Y-m-d H:i:s');
        $this->sync();
    }

    public function sync(): void
    {
        parent::sync();
    }

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

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::name => $this->entity->name = (string)$value,
            self::sessionToken => $this->entity->session_token = is_scalar($value) ? (string)$value : null,
            self::lastActivity => $this->entity->last_activity = is_scalar($value) ? (string)$value : null,
            default => parent::__set($property, $value),
        };
    }

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
