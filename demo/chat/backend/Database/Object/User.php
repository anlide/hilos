<?php

namespace Demo\Chat\Database\Object;

use Demo\Chat\Database\Entity\User as EntityUser;
use Hilos\Database\Object\Object_;
use Hilos\Exception\DatabaseException;

/**
 * User Object
 * Auto-generated from Entity: Demo\Chat\Database\Entity\User
 *
 * Business logic layer with change tracking
 *
 * @property-read ?int $id
 * @property string $name
 * @property ?string $sessionToken
 * @property ?string $lastActivity
 */
final class User extends Object_
{
    // Property name constants (camelCase for PHP)
    public const string id = 'id';
    public const string name = 'name';
    public const string sessionToken = 'sessionToken';
    public const string lastActivity = 'lastActivity';

    protected EntityUser $entity;
    protected EntityUser $entitySync;

    /**
     * Create new empty user object
     */
    public static function create(): self
    {
        $obj = new self();
        $obj->entity = EntityUser::getEmpty();
        $obj->entitySync = clone $obj->entity;
        return $obj;
    }

    /**
     * Create from entity
     */
    public static function fromEntity(EntityUser $entity): self
    {
        $obj = new self();
        $obj->entity = $entity;
        $obj->entitySync = clone $entity;
        return $obj;
    }

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
     * If sessionToken is provided and user exists - returns existing user and updates activity
     * If sessionToken is empty or user not found - creates new user with new session token
     * 
     * @param string $sessionToken Session token (32 hex characters) or empty string
     * @return self User object with valid session token
     */
    public static function loginOrRegister(string $sessionToken): self
    {
        // Try to find existing user by session token
        if (!empty($sessionToken) && strlen($sessionToken) === 32) {
            $user = self::getBySessionToken($sessionToken);
            
            if ($user !== null) {
                // User found and not blocked - update activity and return
                $user->updateActivity();
                return $user;
            }
        }
        
        // User not found or invalid token - create new user
        $user = self::create();
        $user->entity->name = 'User' . mt_rand(1000, 9999); // Generate random name
        $user->entity->session_token = self::generateSessionToken();
        $user->entity->last_activity = date('Y-m-d H:i:s');
        $user->sync();
        
        return $user;
    }

    /**
     * Register new user
     * 
     * Creates a new user with the provided session token.
     * If sessionToken already exists, throws DatabaseException.
     * 
     * @param string $sessionToken Session token (32 hex characters)
     * @return self Newly registered user object
     * @throws DatabaseException If registration fails (e.g., invalid token format, duplicate session token, database error)
     */
    public static function register(string $sessionToken): self
    {
        // Validate token format (should be 32 hex characters)
        if (strlen($sessionToken) !== 32 || !ctype_xdigit($sessionToken)) {
            throw new DatabaseException("Invalid session token format. Expected 32 hex characters.");
        }

        // Check if token already exists
        $existingUser = self::getBySessionToken($sessionToken);
        if ($existingUser !== null) {
            throw new DatabaseException("User with session token already exists");
        }

        // Create new user
        $user = self::create();
        $user->entity->name = 'User' . mt_rand(1000, 9999); // Generate random name
        $user->entity->session_token = $sessionToken;
        $user->entity->last_activity = date('Y-m-d H:i:s');
        
        // Save to database (may throw DatabaseException on failure)
        try {
            $user->sync();
        } catch (DatabaseException $e) {
            // Re-throw with more context if needed
            throw new DatabaseException("Failed to register user: " . $e->getMessage(), $e->getCode(), $e);
        }

        return $user;
    }

    /**
     * Generate new session token (32 hex characters = 16 bytes)
     */
    private static function generateSessionToken(): string
    {
        return bin2hex(random_bytes(16)); // 16 bytes = 32 hex characters
    }

    /**
     * Update user activity timestamp
     */
    public function updateActivity(): void
    {
        $this->entity->last_activity = date('Y-m-d H:i:s');
        $this->sync();
    }

    /**
     * Override sync to add custom logic
     */
    public function sync(): void
    {
        parent::sync();
    }

    /**
     * Property getter
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
     * Property setter
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
     * Convert to array
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

    /**
     * Get ID as string (for use as array key)
     *
     * @return string ID as string
     * @throws DatabaseException If ID is null
     */
    public function getIdString(): string
    {
        if ($this->entity->id === null) {
            throw new DatabaseException("Cannot get ID string: User ID is null");
        }
        return (string)$this->entity->id;
    }
}

