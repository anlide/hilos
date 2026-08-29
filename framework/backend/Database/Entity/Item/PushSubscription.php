<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\PushSubscriptions as EntityPushSubscriptions;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * PushSubscription Entity - represents the hilos_push_subscription table row.
 *
 * One browser push subscription of one user's device (HIL-199). A device opts in
 * from the profile section, which registers the service worker, subscribes through
 * the browser push manager, and sends the resulting endpoint plus its `p256dh` / `auth`
 * keys; the row is the address a push delivery is sent to. `endpoint` is the device
 * identity and is UNIQUE — a re-subscribe (rotated keys, or the same device under a
 * new user) upserts the one row. `user_id` is a soft ref with no cross-boundary FK,
 * matching the hilos_session / hilos_notification_preference convention, and is
 * indexed so a user's subscriptions resolve on the leftmost prefix. Framework holds
 * the contract; projects activate the table thinly (copy the migration stub) and the
 * framework DbContext exposes the collection.
 *
 * @method static EntityPushSubscriptions get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityPushSubscriptions getAll()
 */
final class PushSubscription extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string endpoint = 'endpoint';
    public const string p256dh = 'p256dh';
    public const string auth = 'auth';
    public const string user_agent = 'user_agent';
    public const string created_at = 'created_at';
    public const string last_seen_at = 'last_seen_at';

    public const string _table = 'hilos_push_subscription';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::endpoint,
        self::p256dh,
        self::auth,
        self::user_agent,
        self::created_at,
        self::last_seen_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::endpoint => PhpType::STRING->value,
        self::p256dh => PhpType::STRING->value,
        self::auth => PhpType::STRING->value,
        self::user_agent => PhpType::STRING->value,
        self::created_at => PhpType::DATETIME->value,
        self::last_seen_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_push_subscription_endpoint' => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::endpoint],
        ],
        'idx_push_subscription_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    // An endpoint addresses one person's browser and is a live credential of it; a
    // restored copy that kept it could push to a real device.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $user_id;
    public string $endpoint = '';
    public string $p256dh = '';
    public string $auth = '';
    public ?string $user_agent = null;
    public string $created_at;
    public ?string $last_seen_at = null;
}
