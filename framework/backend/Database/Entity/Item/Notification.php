<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\Notifications as EntityNotifications;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Notification Entity - represents the hilos_notification table row.
 *
 * Framework-standardized durable notification (HIL-102): one row is a delivered
 * notification for one recipient (`user_id`, soft ref, no cross-boundary FK).
 * Framework holds the contract; projects activate the table thinly (copy the
 * migration stub) and the framework DbContext exposes the collection.
 *
 * `type` is a machine key; `title`/`body` are rendered at emit time; `data`
 * carries structured context (stored as JSON, handled as a string in PHP).
 *
 * @method static EntityNotifications get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityNotifications getAll()
 */
final class Notification extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string type = 'type';
    public const string severity = 'severity';
    public const string title = 'title';
    public const string body = 'body';
    public const string data = 'data';
    public const string read_at = 'read_at';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_notification';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::type,
        self::severity,
        self::title,
        self::body,
        self::data,
        self::read_at,
        self::created_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::type => PhpType::STRING->value,
        self::severity => PhpType::STRING->value,
        self::title => PhpType::STRING->value,
        self::body => PhpType::TEXT->value,
        self::data => PhpType::JSON->value,
        self::read_at => PhpType::DATETIME->value,
        self::created_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'idx_notification_user_read' => [Entity::INDEX_COLUMNS => [self::user_id, self::read_at]],
        'idx_notification_user_created' => [Entity::INDEX_COLUMNS => [self::user_id, self::created_at]],
    ];

    public ?int $id = null;
    public int $user_id;
    public string $type;
    public string $severity = 'info';
    public string $title;
    public ?string $body = null;
    public ?string $data = null;
    public ?string $read_at = null;
    public ?string $created_at = null;
}
