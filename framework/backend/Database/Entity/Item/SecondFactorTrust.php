<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\SecondFactorTrusts as EntitySecondFactorTrusts;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * SecondFactorTrust Entity - represents the hilos_second_factor_trust table row.
 *
 * "Don't ask again on this device" (HIL-494): the pair of a browser and a person the
 * second-factor step is skipped for until `trusted_until`. The browser is its session ROW
 * (`session_id`), which outlives the token rotated on every sign-in; the person is part of
 * the key because one browser may serve two people.
 *
 * @method static EntitySecondFactorTrusts get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySecondFactorTrusts getAll()
 */
final class SecondFactorTrust extends Entity
{
    public const string id = 'id';
    public const string session_id = 'session_id';
    public const string user_id = 'user_id';
    public const string trusted_until = 'trusted_until';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_second_factor_trust';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::session_id,
        self::user_id,
        self::trusted_until,
        self::created_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::session_id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::trusted_until => PhpType::DATETIME->value,
        self::created_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_second_factor_trust' => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::session_id, self::user_id],
        ],
        'idx_second_factor_trust_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
    ];

    // The person and not the session: a trust is a statement about somebody's account,
    // and the session it names is standalone (it exists before anybody signs in).
    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // The sessions a trust points at are purged by a restore that anonymizes, so a trust
    // left behind would name a browser nobody has.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $session_id;
    public int $user_id;
    public string $trusted_until;
    public string $created_at;
}
