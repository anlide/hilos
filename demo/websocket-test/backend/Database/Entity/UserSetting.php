<?php

namespace Demo\WebSocketTest\Database\Entity;

use Hilos\Database\Entity\Entity;
use Hilos\Database\PhpType;

/**
 * UserSetting Entity
 * Auto-generated from table: user_setting
 */
final class UserSetting extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string key = 'key';
    public const string value = 'value';

    // Table meta information
    public const string _table = 'user_setting';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::key,
        self::value,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::key => PhpType::STRING->value,
        self::value => PhpType::TEXT->value,
    ];

    // Indexes
    public const array _indexes = [
        'user_id_key' => ['unique' => true, 'columns' => [self::user_id, self::key]],
        'user_id' => ['columns' => [self::user_id]],
    ];

    // Properties
    public ?int $id = null;
    public int $user_id;
    public string $key;
    public ?string $value = null;
}
