<?php

namespace Demo\WebSocketTest\Database\Object;

use Demo\WebSocketTest\Database\Entity\User as EntityUser;
use Hilos\Database\Object\Object_;

/**
 * User Object
 * Business logic layer with change tracking
 * 
 * @property-read int $idUser
 * @property-read string $email
 * @property string $name
 * @property ?string $theme
 * @property ?int $idLanguage
 * @property ?int $idLocale
 * @property bool $admin
 * @property bool $block
 * @property ?int $willDelete
 */
final class User extends Object_
{
    // Property name constants (camelCase for PHP)
    public const string idUser = 'idUser';
    public const string email = 'email';
    public const string name = 'name';
    public const string theme = 'theme';
    public const string idLanguage = 'idLanguage';
    public const string idLocale = 'idLocale';
    public const string admin = 'admin';
    public const string block = 'block';
    public const string willDelete = 'willDelete';

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
     * Load user by email
     */
    public static function getByEmail(string $email): ?self
    {
        $collection = EntityUser::get(['email' => $email]);
        $entity = $collection->first();
        return $entity !== null ? self::fromEntity($entity) : null;
    }

    /**
     * Register new user
     */
    public static function register(string $email, string $password, string $name): self
    {
        $user = self::create();
        $user->entity->email = $email;
        $user->entity->name = $name;
        $user->setPassword($password);
        $user->sync();
        return $user;
    }

    /**
     * Set password (generates hash and salt)
     */
    public function setPassword(string $password): void
    {
        $salt = bin2hex(random_bytes(16));
        $hash = hash('sha256', $salt . $password);
        
        $this->entity->salt = $salt;
        $this->entity->password_hash = $hash;
    }

    /**
     * Verify password
     */
    public function verifyPassword(string $password): bool
    {
        $hash = hash('sha256', $this->entity->salt . $password);
        return hash_equals($this->entity->password_hash, $hash);
    }

    /**
     * Try to login
     */
    public static function tryToLogin(string $email, string $password): ?self
    {
        $user = self::getByEmail($email);
        
        if ($user === null) {
            return null;
        }

        if ($user->block) {
            return null; // User is blocked
        }

        if (!$user->verifyPassword($password)) {
            return null; // Wrong password
        }

        return $user;
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
            self::idUser => $this->entity->id_user,
            self::email => $this->entity->email,
            self::name => $this->entity->name,
            self::theme => $this->entity->theme,
            self::idLanguage => $this->entity->id_language,
            self::idLocale => $this->entity->id_locale,
            self::admin => $this->entity->admin,
            self::block => $this->entity->block,
            self::willDelete => $this->entity->will_delete,
            default => parent::__get($property),
        };
    }

    /**
     * Property setter
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::email => $this->entity->email = (string)$value,
            self::name => $this->entity->name = (string)$value,
            self::theme => $this->entity->theme = is_scalar($value) ? (string)$value : null,
            self::idLanguage => $this->entity->id_language = is_numeric($value) ? (int)$value : null,
            self::idLocale => $this->entity->id_locale = is_numeric($value) ? (int)$value : null,
            self::admin => $this->entity->admin = (bool)$value,
            self::block => $this->entity->block = (bool)$value,
            self::willDelete => $this->entity->will_delete = is_numeric($value) ? (int)$value : null,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            self::idUser => $this->entity->id_user,
            self::email => $this->entity->email,
            self::name => $this->entity->name,
            self::theme => $this->entity->theme,
            self::idLanguage => $this->entity->id_language,
            self::idLocale => $this->entity->id_locale,
            self::admin => $this->entity->admin,
            self::block => $this->entity->block,
            self::willDelete => $this->entity->will_delete,
        ];
    }
}

