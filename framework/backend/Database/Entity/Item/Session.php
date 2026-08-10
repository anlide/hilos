<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\Sessions as EntitySessions;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Session Entity - represents the hilos_session table row.
 *
 * Framework-standardized session (HIL-361): transient and cookie-token keyed,
 * separate from the durable project-owned `user`. A session is anonymous when
 * `user_id` is null, or authenticated once bound to a user at login/register.
 * Framework holds the contract; projects activate the table thinly (copy the
 * migration stub) and the framework DbContext exposes the collection.
 *
 * When `impersonator_user_id` is set, an admin is acting as another user through
 * this session (HIL-166): `user_id` is the impersonation target and the marker
 * holds the admin to restore on stop.
 *
 * @method static EntitySessions get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySessions getAll()
 */
final class Session extends Entity
{
    public const string id = 'id';
    public const string token = 'token';
    public const string user_id = 'user_id';
    public const string impersonator_user_id = 'impersonator_user_id';
    public const string created_at = 'created_at';
    public const string last_seen_at = 'last_seen_at';
    public const string expires_at = 'expires_at';

    public const string _table = 'hilos_session';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::token,
        self::user_id,
        self::impersonator_user_id,
        self::created_at,
        self::last_seen_at,
        self::expires_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::token => PhpType::STRING->value,
        self::user_id => PhpType::INTEGER->value,
        self::impersonator_user_id => PhpType::INTEGER->value,
        self::created_at => PhpType::DATETIME->value,
        self::last_seen_at => PhpType::DATETIME->value,
        self::expires_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_session_token' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::token]],
        'idx_session_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public ?int $id = null;
    public string $token;
    public ?int $user_id = null;
    public ?int $impersonator_user_id = null;
    public string $created_at;
    public string $last_seen_at;
    public ?string $expires_at = null;
}
