<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\SecondFactorTrustRestrictions as EntitySecondFactorTrustRestrictions;
use Hilos\Database\PhpType;

/**
 * SecondFactorTrustRestriction Entity - represents the hilos_second_factor_trust_restriction table row.
 *
 * One shortening of browser trust (HIL-1900): the trusts granted no later than `saved_at` live no
 * longer than `saved_at` + `term_days` days, and a term of 0 takes them out. A row is written once,
 * when an administrator shortens the trust term or switches the second factor off with the trusted
 * browsers forgotten, and is never changed. The moment is signed epoch milliseconds.
 *
 * @method static EntitySecondFactorTrustRestrictions get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySecondFactorTrustRestrictions getAll()
 */
class SecondFactorTrustRestriction extends Entity
{
    public const string id = 'id';
    public const string saved_at = 'saved_at';
    public const string term_days = 'term_days';

    public const string _table = 'hilos_second_factor_trust_restriction';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::saved_at,
        self::term_days,
    ];

    public const array _moments = [self::saved_at];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::saved_at => PhpType::INTEGER->value,
        self::term_days => PhpType::INTEGER->value,
    ];

    public const array _foreign = [];

    public const array _indexes = [
        'idx_second_factor_trust_restriction_saved' => [Entity::INDEX_COLUMNS => [self::saved_at]],
    ];

    // A restriction is a fact of the installation's policy, not of anybody's account.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    // A save moment and a number of days name nobody.
    public const array _pii = [];
    public const array _piiNotPersonal = [
        self::id,
        self::saved_at,
        self::term_days,
    ];

    public ?int $id = null;
    public int $saved_at;
    public int $term_days;
}
