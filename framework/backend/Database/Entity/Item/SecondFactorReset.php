<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\SecondFactorResets as EntitySecondFactorResets;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * SecondFactorReset Entity - represents the hilos_second_factor_reset table row.
 *
 * A delayed removal of a person's second factor (HIL-494): asked for at `requested_at`,
 * carried out at `effective_at`, announced on every channel meanwhile - `notified_at` is
 * the last announcement. The cancel link carries a token whose sha256 is
 * `cancel_token_hash`; the token itself is never stored. Live while neither
 * `canceled_at` nor `completed_at` is set.
 *
 * @method static EntitySecondFactorResets get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySecondFactorResets getAll()
 */
final class SecondFactorReset extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string requested_at = 'requested_at';
    public const string effective_at = 'effective_at';
    public const string cancel_token_hash = 'cancel_token_hash';
    public const string notified_at = 'notified_at';
    public const string canceled_at = 'canceled_at';
    public const string completed_at = 'completed_at';

    public const string _table = 'hilos_second_factor_reset';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::requested_at,
        self::effective_at,
        self::cancel_token_hash,
        self::notified_at,
        self::canceled_at,
        self::completed_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::requested_at => PhpType::DATETIME->value,
        self::effective_at => PhpType::DATETIME->value,
        self::cancel_token_hash => PhpType::STRING->value,
        self::notified_at => PhpType::DATETIME->value,
        self::canceled_at => PhpType::DATETIME->value,
        self::completed_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'idx_second_factor_reset_effective' => [Entity::INDEX_COLUMNS => [self::effective_at]],
        'idx_second_factor_reset_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // A request names an account and holds the hash of a token that cancels it; restored
    // under anonymization it would go on to strip the second factor of a masked person.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $user_id;
    public string $requested_at;
    public string $effective_at;
    public string $cancel_token_hash;
    public string $notified_at;
    public ?string $canceled_at = null;
    public ?string $completed_at = null;
}
