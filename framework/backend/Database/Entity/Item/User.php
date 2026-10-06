<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\Users as EntityUsers;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\PhpType;

/**
 * Framework person row. Projects add columns by extending the whole ORM chain
 * under the users key (docs/agents/architecture/people-table.md).
 *
 * @method static EntityUsers get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUsers getAll()
 */
class User extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string name = 'name';
    public const string admin = 'admin';
    public const string block = 'block';
    public const string last_activity = 'last_activity';

    // Table meta information
    public const string _table = 'hilos_user';
    public const bool _journaled = true;
    public const array _journalNoise = [self::last_activity => 'Changes on every user request'];
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::name,
        self::admin,
        self::block,
        self::last_activity,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::admin => PhpType::BOOLEAN->value,
        self::block => PhpType::BOOLEAN->value,
        self::last_activity => PhpType::DATETIME->value,
    ];

    // Indexes
    public const array _indexes = [
        'idx_user_admin' => [Entity::INDEX_COLUMNS => [self::admin]],
        'idx_user_block' => [Entity::INDEX_COLUMNS => [self::block]],
        'idx_user_last_activity' => [Entity::INDEX_COLUMNS => [self::last_activity]],
    ];

    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = true;

    // A display name is derived from the primary key rather than masked, so a restored copy
    // still tells its people apart.
    public const array _pii = [self::name => AnonymizationStrategy::FAKE_NAME];

    public const array _piiNotPersonal = [
        self::id,
        self::admin,
        self::block,
        self::last_activity,
    ];

    // Properties
    public ?int $id = null;
    public string $name;
    public bool $admin = false;
    public bool $block = false;
    public ?string $last_activity = null;
}
