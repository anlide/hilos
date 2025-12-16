<?php

namespace Demo\WebSocketTest\Database\Entity;

use Hilos\Database\Entity\Entity;
use Hilos\Database\PhpType;

/**
 * User Entity
 * Direct mapping to 'user' table in database
 */
final class User extends Entity
{
    // Column name constants (as they are in database)
    public const string id = 'id';
    public const string password_hash = 'password_hash';
    public const string salt = 'salt';
    public const string name = 'name';
    public const string theme = 'theme';
    public const string admin = 'admin';
    public const string block = 'block';
    public const string will_delete = 'will_delete';

    // Table meta information
    public const string _table = 'user';
    public const string _primary = self::id;
    
    public const array _columns = [
        self::id,
        self::password_hash,
        self::salt,
        self::name,
        self::theme,
        self::admin,
        self::block,
        self::will_delete,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::password_hash => PhpType::STRING->value,
        self::salt => PhpType::STRING->value,
        self::name => PhpType::STRING->value,
        self::theme => PhpType::STRING->value,
        self::admin => PhpType::BOOLEAN->value,
        self::block => PhpType::BOOLEAN->value,
        self::will_delete => PhpType::INTEGER->value,
    ];
    // Indexes
    public const array _indexes = [
        'admin' => ['columns' => [self::admin]],
        'block' => ['columns' => [self::block]],
    ];
    // Properties (exact names as in database)
    public ?int $id = null;
    public string $password_hash;
    public string $salt;
    public string $name;
    public ?string $theme = null;
    public bool $admin = false;
    public bool $block = false;
    public ?int $will_delete = null;
}

