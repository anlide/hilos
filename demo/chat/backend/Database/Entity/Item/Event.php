<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\Events as EntityEvents;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Event - Entity representing event table row.
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
    public const string type = 'type';
    public const string timestamp = 'timestamp';

    // Table meta information
    public const string _table = 'event';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::type,
        self::timestamp,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::type => PhpType::STRING->value,
        self::timestamp => PhpType::DATETIME->value,
    ];

    // Foreign keys
    public const array _foreign = [];

    // Indexes
    public const array _indexes = [
        'type' => [Entity::INDEX_COLUMNS => [self::type]],
        'timestamp' => [Entity::INDEX_COLUMNS => [self::timestamp]],
    ];

    // An event is a type and a moment; who it was about is held by the row that extends it.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::type,
        self::timestamp,
    ];

    // Properties
    public ?int $id = null;
    public string $type;
    public string $timestamp;
}
