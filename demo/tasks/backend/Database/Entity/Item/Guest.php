<?php

namespace Demo\Tasks\Database\Entity\Item;

use Demo\Tasks\Database\Entity\Collection\Guests as EntityGuests;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Guest - Entity representing guest table row.
 *
 * Auto-generated from table: guest.
 * Used by DbCollection and ObjectCollection for ORM layer.
 *
 * The display name a visitor without an account is known by (HIL-610), keyed by
 * the session token that earned it. Deliberately a project table and not a
 * framework one: the framework knows a session may be anonymous, it does not
 * know that this demo chooses to call an anonymous one Guest1234.
 *
 * @method static EntityGuests get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityGuests getAll()
 */
final class Guest extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string session_token = 'session_token';
    public const string name = 'name';
    public const string created_at = 'created_at';

    // Table meta information
    public const string _table = 'guest';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::session_token,
        self::name,
        self::created_at,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::session_token => PhpType::STRING->value,
        self::name => PhpType::STRING->value,
        self::created_at => PhpType::DATETIME->value,
    ];

    // Indexes
    public const array _indexes = [
        'uk_guest_session' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::session_token]],
    ];

    // Properties
    public ?int $id = null;
    public string $session_token;
    public string $name;
    public string $created_at;
}
