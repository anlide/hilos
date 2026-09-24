<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\SecondFactorBackupCodes as EntitySecondFactorBackupCodes;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * SecondFactorBackupCode Entity - represents the hilos_second_factor_backup_code table row.
 *
 * One one-shot backup code of a person's second factor (HIL-494). `used_at` burns the code.
 *
 * The `code` column is DB-only: it is intentionally absent from _columns and from the
 * object/view ORM layer, and is read and written only through the collection's and the
 * object's own primitives. It is stored normalized and not hashed - the authenticator
 * secret beside it has to stay readable anyway, so a dump of the database opens the
 * account with or without these - but it never crosses the object, view, or cross-worker
 * sync boundary.
 *
 * @object-exclude code
 *
 * @method static EntitySecondFactorBackupCodes get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySecondFactorBackupCodes getAll()
 */
final class SecondFactorBackupCode extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    /** DB-only code column (see @object-exclude); read and written by targeted queries, never ORM-mapped. */
    public const string code = 'code';
    public const string used_at = 'used_at';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_second_factor_backup_code';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::used_at,
        self::created_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::used_at => PhpType::DATETIME->value,
        self::created_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'idx_second_factor_backup_code_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // A backup code is a password to the account; there is nothing to keep once it is masked.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $user_id;
    public ?string $used_at = null;
    public string $created_at;
}
