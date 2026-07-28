<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\NotificationPreferences as EntityNotificationPreferences;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * NotificationPreference Entity - represents the hilos_notification_preference table row.
 *
 * Per-user notification channel preference (HIL-485). Sparse opt-out: a row exists
 * only to record a deviation from the "channel allowed" default, so its presence
 * means the recipient muted the named channel (`enabled` is always stored `false`;
 * re-enabling deletes the row rather than flipping the flag). `user_id` is a soft
 * ref with no cross-boundary FK, matching the hilos_session / hilos_notification
 * convention. Framework holds the contract; projects activate the table thinly
 * (copy the migration stub) and the framework DbContext exposes the collection.
 *
 * @method static EntityNotificationPreferences get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityNotificationPreferences getAll()
 */
final class NotificationPreference extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string channel = 'channel';
    public const string enabled = 'enabled';
    public const string created_at = 'created_at';
    public const string updated_at = 'updated_at';

    public const string _table = 'hilos_notification_preference';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::channel,
        self::enabled,
        self::created_at,
        self::updated_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::channel => PhpType::STRING->value,
        self::enabled => PhpType::BOOLEAN->value,
        self::created_at => PhpType::DATETIME->value,
        self::updated_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_notification_preference_user_channel' => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::user_id, self::channel],
        ],
    ];

    public ?int $id = null;
    public ?int $user_id = null;
    public string $channel = '';
    public bool $enabled = false;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
