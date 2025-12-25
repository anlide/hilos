<?php

namespace Demo\WebSocketTest\Database\Entity;

use Hilos\Database\Entity\Entity;
use Demo\WebSocketTest\Database\Entity\User as EntityUser;
use Hilos\Database\PhpType;

/**
 * Message Entity
 * Auto-generated from table: message
 */
final class Message extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string message = 'message';
    public const string timestamp = 'timestamp';

    // Table meta information
    public const string _table = 'message';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::message,
        self::timestamp,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::message => PhpType::STRING->value,
        self::timestamp => PhpType::DATETIME->value,
    ];

    // Foreign keys
    public const array _foreign = [
        self::user_id => EntityUser::_table,
    ];

    // Indexes
    public const array _indexes = [
        'timestamp' => ['columns' => [self::timestamp]],
        'user_id' => ['columns' => [self::user_id]],
    ];

    // Properties
    public ?int $id = null;
    public int $user_id;
    public string $message;
    public ?string $timestamp = 'current_timestamp()';
}
