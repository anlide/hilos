<?php

namespace Demo\WebSocketTest\Database\Entity;

use Hilos\Database\Entity\Entity;

/**
 * User Entity
 * Direct mapping to 'user' table in database
 */
final class User extends Entity
{
    // Column name constants (as they are in database)
    public const string id_user = 'id_user';
    public const string email = 'email';
    public const string password_hash = 'password_hash';
    public const string salt = 'salt';
    public const string name = 'name';
    public const string theme = 'theme';
    public const string id_language = 'id_language';
    public const string id_locale = 'id_locale';
    public const string admin = 'admin';
    public const string block = 'block';
    public const string will_delete = 'will_delete';
    public const string created_at = 'created_at';

    // Table meta information
    public const string _table = 'user';
    public const string _primary = self::id_user;
    
    public const array _columns = [
        self::id_user,
        self::email,
        self::password_hash,
        self::salt,
        self::name,
        self::theme,
        self::id_language,
        self::id_locale,
        self::admin,
        self::block,
        self::will_delete,
        self::created_at
    ];

    // Column types
    public const array _types = [
        self::id_user => 'integer',
        self::email => 'string',
        self::password_hash => 'string',
        self::salt => 'string',
        self::name => 'string',
        self::theme => 'string',
        self::id_language => 'integer',
        self::id_locale => 'integer',
        self::admin => 'boolean',
        self::block => 'boolean',
        self::will_delete => 'integer',
        self::created_at => 'datetime',
    ];

    // Foreign keys
    public const array _foreign = [
        self::id_locale => 'locale',
        self::id_language => 'language',
    ];

    // Indexes
    public const array _indexes = [
        'email' => ['unique' => true, 'columns' => ['email']],
        'admin' => ['columns' => ['admin']],
    ];

    // Properties (exact names as in database)
    public ?int $id_user = null;
    public string $email;
    public string $password_hash;
    public string $salt;
    public string $name;
    public ?string $theme = null;
    public ?int $id_language = null;
    public ?int $id_locale = null;
    public bool $admin = false;
    public bool $block = false;
    public ?int $will_delete = null;
    public ?string $created_at = null;
}

