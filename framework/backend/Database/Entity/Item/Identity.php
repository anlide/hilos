<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\Identities as EntityIdentities;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Identity Entity - represents hilos_identity table row.
 *
 * Pluggable multi-method auth identity (HIL-160): one project-owned `user` may
 * own many identities, uniquely keyed by (type, identifier). Framework holds the
 * contract; projects activate the table thinly (copy the migration stub) and the
 * framework DbContext exposes the collection.
 *
 * The `secret` column (password hash; NULL for external methods) is DB-only: it
 * is intentionally absent from _columns and from the object/view ORM layer, and
 * is read only through the identity layer's verify primitive
 * ({@see \Hilos\Database\Object\Item\Identity::verifyPassword()}). The hash never
 * crosses the object, view, frontend, or cross-worker sync boundary.
 *
 * @object-exclude secret
 *
 * @method static EntityIdentities get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityIdentities getAll()
 */
final class Identity extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string type = 'type';
    public const string identifier = 'identifier';
    /** DB-only secret column (see @object-exclude); referenced by the verify query, never ORM-mapped. */
    public const string secret = 'secret';
    public const string provider = 'provider';
    public const string verified = 'verified';

    public const string _table = 'hilos_identity';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::type,
        self::identifier,
        self::provider,
        self::verified,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::type => PhpType::STRING->value,
        self::identifier => PhpType::STRING->value,
        self::provider => PhpType::STRING->value,
        self::verified => PhpType::BOOLEAN->value,
    ];

    public const array _indexes = [
        'uk_identity_type_identifier' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::type, self::identifier]],
        'idx_identity_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public ?int $id = null;
    public int $user_id;
    public string $type;
    public string $identifier;
    public ?string $provider = null;
    public bool $verified = false;
}
