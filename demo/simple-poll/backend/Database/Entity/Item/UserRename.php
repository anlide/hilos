<?php

namespace Demo\SimplePoll\Database\Entity\Item;

use Demo\SimplePoll\Database\Entity\Collection\UserRenames as EntityUserRenames;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * UserRename - Entity representing user_rename table row.
 *
 * Stores one admin user-rename audit row: the renamed user, the before/after
 * display names, and when. Standalone (its own id + timestamp), no event
 * aggregator parent.
 *
 * @method static EntityUserRenames get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUserRenames getAll()
 */
final class UserRename extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string target_user_id = 'target_user_id';
    public const string old_name = 'old_name';
    public const string new_name = 'new_name';
    public const string timestamp = 'timestamp';

    // Table meta information
    public const string _table = 'user_rename';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::target_user_id,
        self::old_name,
        self::new_name,
        self::timestamp,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::target_user_id => PhpType::INTEGER->value,
        self::old_name => PhpType::STRING->value,
        self::new_name => PhpType::STRING->value,
        self::timestamp => PhpType::DATETIME->value,
    ];

    // Foreign keys
    public const array _foreign = [
        self::target_user_id => User::_table,
    ];

    // Indexes
    public const array _indexes = [
        'target_user_id' => [Entity::INDEX_COLUMNS => [self::target_user_id]],
        'timestamp' => [Entity::INDEX_COLUMNS => [self::timestamp]],
    ];

    // Properties
    public ?int $id = null;
    public int $target_user_id;
    public string $old_name = '';
    public string $new_name = '';
    public string $timestamp;
}
