<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\SecondFactors as EntitySecondFactors;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\SecondFactor as ObjectSecondFactor;
use Hilos\Database\PhpType;

/**
 * SecondFactor Entity - represents the hilos_second_factor table row.
 *
 * One authenticator app a person enrolled as their second factor (HIL-494).
 * `confirmed_at` NULL is an enrolment not finished yet - such a row is no second factor
 * anywhere. `last_used_step` is the replay guard: the 30-second step of the last code
 * accepted from this app.
 *
 * The `secret` column (the shared TOTP secret in base32) is DB-only: it is intentionally
 * absent from _columns and from the object/view ORM layer, and is read and written only
 * through the object's own primitives ({@see ObjectSecondFactor::readSecret()},
 * {@see ObjectSecondFactor::writeSecret()}). It stays readable, because every code is
 * computed from it, but it never crosses the object, view, frontend, or cross-worker sync
 * boundary; it leaves the framework once, in the answer that starts the enrolment.
 *
 * @object-exclude secret
 *
 * @method static EntitySecondFactors get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySecondFactors getAll()
 */
final class SecondFactor extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string label = 'label';
    /** DB-only secret column (see @object-exclude); read and written by targeted queries, never ORM-mapped. */
    public const string secret = 'secret';
    public const string last_used_step = 'last_used_step';
    public const string confirmed_at = 'confirmed_at';
    public const string last_used_at = 'last_used_at';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_second_factor';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::label,
        self::last_used_step,
        self::confirmed_at,
        self::last_used_at,
        self::created_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::label => PhpType::STRING->value,
        self::last_used_step => PhpType::INTEGER->value,
        self::confirmed_at => PhpType::DATETIME->value,
        self::last_used_at => PhpType::DATETIME->value,
        self::created_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'idx_second_factor_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // The secret opens the account; a masked one would be an authenticator nobody can
    // produce a code for, so a restore that anonymizes empties the table instead.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $user_id;
    public string $label;
    public ?int $last_used_step = null;
    public ?string $confirmed_at = null;
    public ?string $last_used_at = null;
    public string $created_at;
}
