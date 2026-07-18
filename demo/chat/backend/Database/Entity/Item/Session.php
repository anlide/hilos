<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\Sessions as EntitySessions;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Session - Entity representing session table row.
 *
 * A session is transient and cookie-token keyed. It is anonymous when `user_id`
 * is null, or authenticated once bound to a durable `user` at login/register.
 *
 * @method static EntitySessions get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySessions getAll()
 */
final class Session extends Entity
{
    public const string id = 'id';
    public const string token = 'token';
    public const string user_id = 'user_id';
    public const string created_at = 'created_at';
    public const string last_seen_at = 'last_seen_at';
    public const string expires_at = 'expires_at';

    public const string _table = 'session';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::token,
        self::user_id,
        self::created_at,
        self::last_seen_at,
        self::expires_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::token => PhpType::STRING->value,
        self::user_id => PhpType::INTEGER->value,
        self::created_at => PhpType::DATETIME->value,
        self::last_seen_at => PhpType::DATETIME->value,
        self::expires_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'token' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::token]],
        'user_id' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public ?int $id = null;
    public string $token;
    public ?int $user_id = null;
    public ?string $created_at = null;
    public ?string $last_seen_at = null;
    public ?string $expires_at = null;
}
