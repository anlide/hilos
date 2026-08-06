<?php

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\Users as EntityUsers;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * User - Entity representing user table row.
 *
 * Auto-generated from table: user.
 * Used by DbCollection and ObjectCollection for ORM layer.
 *
 * @object-exclude password_hash, salt
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
    public const string merged_into = 'merged_into';
    public const string last_activity = 'last_activity';

    // Table meta information
    public const string _table = 'user';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::name,
        self::admin,
        self::block,
        self::merged_into,
        self::last_activity,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::admin => PhpType::BOOLEAN->value,
        self::block => PhpType::BOOLEAN->value,
        self::merged_into => PhpType::INTEGER->value,
        self::last_activity => PhpType::DATETIME->value,
    ];

    // Indexes
    public const array _indexes = [
        'admin' => [Entity::INDEX_COLUMNS => [self::admin]],
        'block' => [Entity::INDEX_COLUMNS => [self::block]],
        'merged_into' => [Entity::INDEX_COLUMNS => [self::merged_into]],
        'last_activity' => [Entity::INDEX_COLUMNS => [self::last_activity]],
    ];

    // Properties
    public ?int $id = null;
    public string $name;
    public bool $admin = false;
    public bool $block = false;
    public ?int $merged_into = null;
    public ?string $last_activity = null;
}
