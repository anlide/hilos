<?php

namespace Demo\Chat\Database\Entity;

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
    public const string name = 'name';
    public const string last_activity = 'last_activity';
    public const string session_token = 'session_token';

    // Table meta information
    public const string _table = 'user';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::name,
        self::session_token,
        self::last_activity,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::session_token => PhpType::STRING->value,
        self::last_activity => PhpType::DATETIME->value,
    ];

    // Indexes
    public const array _indexes = [
        'session_token' => ['unique' => true, 'columns' => [self::session_token]],
        'last_activity' => ['columns' => [self::last_activity]],
    ];

    // Properties
    public ?string $session_token = null;
    public ?string $last_activity = null;
    public ?int $id = null;
    public string $name;
}
