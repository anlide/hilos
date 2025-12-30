<?php

namespace Demo\WebSocketTest\Database\Entity;

use Hilos\Database\Entity\Entity;
use Demo\WebSocketTest\Database\Entity\User as EntityUser;
use Hilos\Database\PhpType;

/**
 * Event Entity
 * Auto-generated from table: event
 */
final class Event extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string type = 'type';
    public const string timestamp = 'timestamp';

    // Table meta information
    public const string _table = 'event';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::type,
        self::timestamp,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::type => PhpType::STRING->value,
        self::timestamp => PhpType::DATETIME->value,
    ];

    // Foreign keys
    public const array _foreign = [
        self::user_id => EntityUser::_table,
    ];

    // Indexes
    public const array _indexes = [
        'type' => ['columns' => [self::type]],
        'timestamp' => ['columns' => [self::timestamp]],
        'user_id' => ['columns' => [self::user_id]],
    ];

    // Properties
    public ?int $id = null;
    public int $user_id;
    public string $type;
    public string $timestamp = 'current_timestamp()';
}
