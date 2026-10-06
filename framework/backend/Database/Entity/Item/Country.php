<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\Countries as EntityCountries;
use Hilos\Database\PhpType;

/**
 * A country of the installation's reference catalog.
 *
 * @method static EntityCountries get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityCountries getAll()
 */
class Country extends Entity
{
    public const string id = 'id';
    public const string code = 'code';
    public const string currency_symbol = 'currency_symbol';
    public const string currency_code = 'currency_code';
    public const string default_locale_id = 'default_locale_id';
    public const string enabled = 'enabled';

    public const string _table = 'hilos_country';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::code,
        self::currency_symbol,
        self::currency_code,
        self::default_locale_id,
        self::enabled,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::code => PhpType::STRING->value,
        self::currency_symbol => PhpType::STRING->value,
        self::currency_code => PhpType::STRING->value,
        self::default_locale_id => PhpType::INTEGER->value,
        self::enabled => PhpType::BOOLEAN->value,
    ];
    public const array _indexes = [
        'uk_country_code' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::code]],
        'idx_country_default_locale' => [Entity::INDEX_COLUMNS => [self::id, self::default_locale_id]],
    ];

    public const string _setVia = Entity::SET_STANDALONE;
    // Country names (HIL-1468) attach to this root.
    public const bool _setRoot = true;

    public const array _pii = [];
    public const array _piiNotPersonal = [
        self::id,
        self::code,
        self::currency_symbol,
        self::currency_code,
        self::default_locale_id,
        self::enabled,
    ];

    public ?int $id = null;
    public string $code;
    public string $currency_symbol;
    public string $currency_code;
    public ?int $default_locale_id = null;
    public bool $enabled = false;
}
