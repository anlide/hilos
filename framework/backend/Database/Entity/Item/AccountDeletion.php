<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\AccountDeletions as EntityAccountDeletions;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * AccountDeletion Entity - represents the hilos_account_deletion table row.
 *
 * A person's own request to delete their account (HIL-302): asked for at `requested_at`,
 * carried out at `effective_at`, which is fixed when the request is made and never moves.
 * Live while neither `canceled_at` nor `completed_at` is set. A carried-out row stays
 * after the account is gone: the number of an account that no longer exists and three
 * dates are the trace that it was erased on request.
 *
 * @method static EntityAccountDeletions get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityAccountDeletions getAll()
 */
final class AccountDeletion extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string requested_at = 'requested_at';
    public const string effective_at = 'effective_at';
    public const string canceled_at = 'canceled_at';
    public const string completed_at = 'completed_at';

    public const string _table = 'hilos_account_deletion';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::requested_at,
        self::effective_at,
        self::canceled_at,
        self::completed_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::requested_at => PhpType::DATETIME->value,
        self::effective_at => PhpType::DATETIME->value,
        self::canceled_at => PhpType::DATETIME->value,
        self::completed_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'idx_account_deletion_effective' => [Entity::INDEX_COLUMNS => [self::effective_at]],
        'idx_account_deletion_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // A request names an account and a moment to erase it; restored under anonymization it
    // would go on to erase a masked person when that moment comes.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $user_id;
    public string $requested_at;
    public string $effective_at;
    public ?string $canceled_at = null;
    public ?string $completed_at = null;
}
