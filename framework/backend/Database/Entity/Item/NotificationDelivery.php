<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\NotificationDeliveries as EntityNotificationDeliveries;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * NotificationDelivery Entity - represents the hilos_notification_delivery table row.
 *
 * One row is one channel's delivery of one notification (HIL-196): the dispatcher
 * inserts it `pending` when it fans a notification to a channel, and the channel's
 * delivery agent moves it to `sent`/`failed` with bounded retries. `notification_id`
 * is a soft ref to hilos_notification (no cross-boundary FK); `channel` is the
 * channel name (a string, not an enum — channels extend by subclassing).
 *
 * @method static EntityNotificationDeliveries get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityNotificationDeliveries getAll()
 */
final class NotificationDelivery extends Entity
{
    public const string id = 'id';
    public const string notification_id = 'notification_id';
    public const string channel = 'channel';
    public const string status = 'status';
    public const string attempts = 'attempts';
    public const string last_error = 'last_error';
    public const string created_at = 'created_at';
    public const string updated_at = 'updated_at';
    public const string delivered_at = 'delivered_at';

    public const string _table = 'hilos_notification_delivery';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::notification_id,
        self::channel,
        self::status,
        self::attempts,
        self::last_error,
        self::created_at,
        self::updated_at,
        self::delivered_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::notification_id => PhpType::INTEGER->value,
        self::channel => PhpType::STRING->value,
        self::status => PhpType::STRING->value,
        self::attempts => PhpType::INTEGER->value,
        self::last_error => PhpType::STRING->value,
        self::created_at => PhpType::DATETIME->value,
        self::updated_at => PhpType::DATETIME->value,
        self::delivered_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'idx_notification_delivery_notification' => [Entity::INDEX_COLUMNS => [self::notification_id]],
        'idx_notification_delivery_channel_status' => [Entity::INDEX_COLUMNS => [self::channel, self::status]],
        'idx_notification_delivery_created' => [Entity::INDEX_COLUMNS => [self::created_at]],
        'idx_notification_delivery_status_created' => [Entity::INDEX_COLUMNS => [self::status, self::created_at]],
    ];

    // A transport's error text quotes what it was given - an address, a token, a body.
    public const array _pii = [self::last_error => AnonymizationStrategy::MASK];

    public const array _piiNotPersonal = [
        self::id,
        self::notification_id,
        self::channel,
        self::status,
        self::attempts,
        self::created_at,
        self::updated_at,
        self::delivered_at,
    ];

    public ?int $id = null;
    public int $notification_id;
    public string $channel;
    public string $status = 'pending';
    public int $attempts = 0;
    public ?string $last_error = null;
    public string $created_at;
    public string $updated_at;
    public ?string $delivered_at = null;
}
