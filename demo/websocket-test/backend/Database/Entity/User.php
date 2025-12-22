<?php

namespace Demo\WebSocketTest\Database\Entity;

use Hilos\Database\Entity\Entity;
use Hilos\Database\PhpType;

/**
 * User Entity
 * Auto-generated from table: user
 * 
 * @object-exclude password_hash, salt
 */
final class User extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string password_hash = 'password_hash';
    public const string salt = 'salt';
    public const string name = 'name';
    public const string theme = 'theme';
    public const string admin = 'admin';
    public const string block = 'block';
    public const string will_delete = 'will_delete';
    public const string last_activity = 'last_activity';
    public const string session_token = 'session_token';

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
        self::session_token,
        self::last_activity,
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
        self::session_token => PhpType::STRING->value,
        self::last_activity => PhpType::DATETIME->value,
    ];

    // Indexes
    public const array _indexes = [
        'session_token' => ['unique' => true, 'columns' => [self::session_token]],
        'admin' => ['columns' => [self::admin]],
        'block' => ['columns' => [self::block]],
        'last_activity' => ['columns' => [self::last_activity]],
    ];

    // Properties
    public ?string $session_token = null;
    public ?string $last_activity = null;
    public ?int $id = null;
    // @object-exclude
    public string $password_hash;
    // @object-exclude
    public string $salt;
    public string $name;
    public ?string $theme = null;
    public bool $admin = false;
    public bool $block = false;
    public ?int $will_delete = null;
}
