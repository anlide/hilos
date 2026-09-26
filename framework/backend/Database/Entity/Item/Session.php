<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
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
 * The row lives until the sessions library sweep removes it after its cookie
 * lifetime ends or after an anonymous browser never returns.
 *
 * When `impersonator_user_id` is set, an admin is acting as another user through
 * this session (HIL-166): `user_id` is the impersonation target and the marker
 * holds the admin to restore on stop. An anonymous row never carries the marker: the
 * sign-out lowers it together with `user_id` (HIL-1061).
 *
 * The `pending_registration_*` pair is the durable memory of a registration this
 * browser started and has not finished (HIL-612): the address whose code it is
 * waiting on, and the moment that wait was last written. It lives here rather than
 * in a table of its own because it is memory ABOUT this session and dies with it -
 * and because a project with no registration then pays a column it never fills
 * instead of a table it must still create.
 *
 * `pending_ack` is the success sentence a finished auth flow still owes this browser
 * and nobody has read yet (HIL-875) - the account is ready, the password changed. It
 * is memory ABOUT this session by the same argument, and it is written here rather
 * than on the socket that earned it because a mark owned by a connection outlived
 * the session it belonged to: a logout restated it, a rotation carried it onto the
 * socket that replaced the marked one, and what the person was left looking at was an
 * announcement about a flow that had already ended.
 *
 * The `pending_second_factor_*` group is a proven sign-in waiting on the person's second
 * factor (HIL-494): whose it is, which screen it waits on, until when, how many wrong codes
 * it took and the sentence owed once it passes. Memory ABOUT this session for the same
 * reason as the two above, and written only by the session holder.
 *
 * `blocked_user_id` is the account this browser lost, or was refused, because that account is
 * blocked (HIL-289): the "Access closed" card is served from it on every handshake until the
 * card's Sign out, a sign-in or an unblock lowers it. Written only by the session holder.
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
    public const string pending_registration_identifier = 'pending_registration_identifier';
    public const string pending_registration_since = 'pending_registration_since';
    public const string pending_ack = 'pending_ack';
    public const string pending_second_factor_user_id = 'pending_second_factor_user_id';
    public const string pending_second_factor_mode = 'pending_second_factor_mode';
    public const string pending_second_factor_until = 'pending_second_factor_until';
    public const string pending_second_factor_attempts = 'pending_second_factor_attempts';
    public const string pending_second_factor_ack = 'pending_second_factor_ack';
    public const string device_name = 'device_name';
    public const string blocked_user_id = 'blocked_user_id';

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
        self::pending_registration_identifier,
        self::pending_registration_since,
        self::pending_ack,
        self::pending_second_factor_user_id,
        self::pending_second_factor_mode,
        self::pending_second_factor_until,
        self::pending_second_factor_attempts,
        self::pending_second_factor_ack,
        self::device_name,
        self::blocked_user_id,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::token => PhpType::STRING->value,
        self::user_id => PhpType::INTEGER->value,
        self::impersonator_user_id => PhpType::INTEGER->value,
        self::created_at => PhpType::DATETIME->value,
        self::last_seen_at => PhpType::DATETIME->value,
        self::expires_at => PhpType::DATETIME->value,
        self::pending_registration_identifier => PhpType::STRING->value,
        self::pending_registration_since => PhpType::DATETIME->value,
        self::pending_ack => PhpType::STRING->value,
        self::pending_second_factor_user_id => PhpType::INTEGER->value,
        self::pending_second_factor_mode => PhpType::STRING->value,
        self::pending_second_factor_until => PhpType::DATETIME->value,
        self::pending_second_factor_attempts => PhpType::INTEGER->value,
        self::pending_second_factor_ack => PhpType::STRING->value,
        self::device_name => PhpType::STRING->value,
        self::blocked_user_id => PhpType::INTEGER->value,
    ];

    public const array _indexes = [
        'uk_session_token' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::token]],
        'idx_session_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
        'idx_session_pending_registration' => [
            Entity::INDEX_COLUMNS => [self::pending_registration_identifier],
        ],
        'idx_session_expires' => [Entity::INDEX_COLUMNS => [self::expires_at]],
        'idx_session_anonymous_seen' => [
            Entity::INDEX_COLUMNS => [self::user_id, self::last_seen_at],
        ],
        'idx_session_pending_second_factor' => [
            Entity::INDEX_COLUMNS => [self::pending_second_factor_user_id],
        ],
        'idx_session_blocked_user' => [Entity::INDEX_COLUMNS => [self::blocked_user_id]],
        'idx_session_impersonator' => [Entity::INDEX_COLUMNS => [self::impersonator_user_id]],
    ];

    // A session is anonymous while user_id is empty, and a nullable column is not an owner:
    // a row that can fall out of the set was never in it.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    // A session token is NOT NULL and UNIQUE, so a hash would leave a structurally
    // valid session behind; nothing points at this table, so emptying it costs nothing.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public string $token;
    public ?int $user_id = null;
    public ?int $impersonator_user_id = null;
    public string $created_at;
    public string $last_seen_at;
    public ?string $expires_at = null;
    public ?string $pending_registration_identifier = null;
    public ?string $pending_registration_since = null;
    public ?string $pending_ack = null;
    public ?int $pending_second_factor_user_id = null;
    public ?string $pending_second_factor_mode = null;
    public ?string $pending_second_factor_until = null;
    public int $pending_second_factor_attempts = 0;
    public ?string $pending_second_factor_ack = null;
    public ?string $device_name = null;
    public ?int $blocked_user_id = null;
}
