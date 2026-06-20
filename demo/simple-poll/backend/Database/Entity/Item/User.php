<?php

namespace Demo\SimplePoll\Database\Entity\Item;

use Demo\SimplePoll\Database\Entity\Collection\Users as EntityUsers;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * User - Entity representing user table row.
 *
 * Auto-generated from table: user.
 * Used by DbCollection and ObjectCollection for ORM layer.
 *
 * @method static EntityUsers get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUsers getAll()
 */
final class User extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string name = 'name';
    public const string admin = 'admin';
    public const string block = 'block';
    public const string session_token = 'session_token';
    public const string last_activity = 'last_activity';

    // Table meta information
    public const string _table = 'user';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::name,
        self::admin,
        self::block,
        self::session_token,
        self::last_activity,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::admin => PhpType::BOOLEAN->value,
        self::block => PhpType::BOOLEAN->value,
        self::session_token => PhpType::STRING->value,
        self::last_activity => PhpType::DATETIME->value,
    ];

    // Indexes
    public const array _indexes = [
        'session_token' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::session_token]],
        'last_activity' => [Entity::INDEX_COLUMNS => [self::last_activity]],
    ];

    // Properties
    public ?int $id = null;
    public string $name;
    public bool $admin = false;
    public bool $block = false;
    public ?string $session_token = null;
    public ?string $last_activity = null;
}
