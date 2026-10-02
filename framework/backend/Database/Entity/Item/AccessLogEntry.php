<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\AccessLogEntries as EntityAccessLogEntries;
use Hilos\Database\PhpType;

/**
 * AccessLogEntry Entity - one use of an account in hilos_access_log (HIL-1174).
 *
 * @method static EntityAccessLogEntries get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityAccessLogEntries getAll()
 */
class AccessLogEntry extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string event = 'event';
    public const string ip_address = 'ip_address';
    public const string occurred_at = 'occurred_at';

    public const string _table = 'hilos_access_log';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::event,
        self::ip_address,
        self::occurred_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::event => PhpType::STRING->value,
        self::ip_address => PhpType::STRING->value,
        self::occurred_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'idx_access_log_user' => [Entity::INDEX_COLUMNS => [self::user_id, self::occurred_at]],
        'idx_access_log_occurred' => [Entity::INDEX_COLUMNS => [self::occurred_at]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // Every row names a person and the address they came from; nothing points at this table.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $user_id;
    public string $event;
    public ?string $ip_address = null;
    public string $occurred_at;
}
