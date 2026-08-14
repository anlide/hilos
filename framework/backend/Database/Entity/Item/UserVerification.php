<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\UserVerifications as EntityUserVerifications;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;
use Hilos\Database\PhpType;

/**
 * UserVerification Entity - represents hilos_user_verification table row.
 *
 * Framework-standardized verification challenge (HIL-365): one typed row backs
 * both email-confirmation and password-recovery, replacing the four copy-pasted
 * per-flow tables of the reference. Framework holds the contract; projects
 * activate the table thinly (copy the migration stub) and the framework
 * DbContext exposes the collection.
 *
 * The `code_hash` column (bcrypt hash of the delivered code) is DB-only: it is
 * intentionally absent from _columns and from the object/view ORM layer, and is
 * read only through the verify primitive
 * ({@see ObjectUserVerification::verifyCode()}), so the
 * hash never crosses the object, view, frontend, or cross-worker sync boundary.
 *
 * @object-exclude code_hash
 *
 * @method static EntityUserVerifications get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUserVerifications getAll()
 */
final class UserVerification extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string type = 'type';
    public const string identifier = 'identifier';
    /** DB-only code hash (see @object-exclude); referenced by the verify query, never ORM-mapped. */
    public const string code_hash = 'code_hash';
    public const string attempts = 'attempts';
    public const string created_at = 'created_at';
    public const string expires_at = 'expires_at';
    public const string consumed_at = 'consumed_at';

    public const string _table = 'hilos_user_verification';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::type,
        self::identifier,
        self::attempts,
        self::created_at,
        self::expires_at,
        self::consumed_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::type => PhpType::STRING->value,
        self::identifier => PhpType::STRING->value,
        self::attempts => PhpType::INTEGER->value,
        self::created_at => PhpType::DATETIME->value,
        self::expires_at => PhpType::DATETIME->value,
        self::consumed_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'idx_uv_type_identifier' => [Entity::INDEX_COLUMNS => [self::type, self::identifier]],
        'idx_uv_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public ?int $id = null;
    public ?int $user_id = null;
    public string $type;
    public string $identifier;
    public int $attempts = 0;
    public string $created_at;
    public string $expires_at;
    public ?string $consumed_at = null;
}
