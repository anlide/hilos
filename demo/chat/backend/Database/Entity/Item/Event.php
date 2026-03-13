<?php

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\Events as EntityEvents;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Event Entity - represents event table row.
 *
 * Auto-generated from table: event.
 * Stores chat events (messages, user actions, system events).
 *
 * @method static EntityEvents get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityEvents getAll()
 */
final class Event extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string bot_id = 'bot_id';
    public const string type = 'type';
    public const string timestamp = 'timestamp';
    public const string data = 'data';

    // Table meta information
    public const string _table = 'event';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::bot_id,
        self::type,
        self::timestamp,
        self::data,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::bot_id => PhpType::INTEGER->value,
        self::type => PhpType::STRING->value,
        self::timestamp => PhpType::DATETIME->value,
        self::data => PhpType::STRING->value,
    ];

    // Foreign keys
    public const array _foreign = [
        self::user_id => User::_table,
        self::bot_id => Bot::_table,
    ];

    // Indexes
    public const array _indexes = [
        'type' => [Entity::INDEX_COLUMNS => [self::type]],
        'timestamp' => [Entity::INDEX_COLUMNS => [self::timestamp]],
        'user_id' => [Entity::INDEX_COLUMNS => [self::user_id]],
        'bot_id' => [Entity::INDEX_COLUMNS => [self::bot_id]],
    ];

    // Properties
    public ?int $id = null;
    public ?int $user_id = null;
    public ?int $bot_id = null;
    public string $type;
    public string $timestamp = 'current_timestamp()';
    public ?string $data = null;
}
