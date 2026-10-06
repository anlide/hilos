<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\Locales as EntityLocales;
use Hilos\Database\PhpType;

/**
 * A locale of the installation's reference catalog.
 *
 * @method static EntityLocales get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityLocales getAll()
 */
class Locale extends Entity
{
    public const int DATE_FORMAT_MAX_CHARS = 32;
    public const int TIME_FORMAT_MAX_CHARS = 32;
    public const int NUMBER_FORMAT_MAX_CHARS = 32;
    public const int PHONE_FORMAT_MAX_CHARS = 32;
    public const int ADDRESS_FORMAT_MAX_CHARS = 64;
    public const int COLLATION_MAX_CHARS = 32;

    public const string id = 'id';
    public const string code = 'code';
    public const string language_id = 'language_id';
    public const string country_id = 'country_id';
    public const string date_format = 'date_format';
    public const string time_format = 'time_format';
    public const string number_format = 'number_format';
    public const string phone_format = 'phone_format';
    public const string address_format = 'address_format';
    public const string measurement_system = 'measurement_system';
    public const string collation = 'collation';
    public const string enabled = 'enabled';

    public const string _table = 'hilos_locale';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::code,
        self::language_id,
        self::country_id,
        self::date_format,
        self::time_format,
        self::number_format,
        self::phone_format,
        self::address_format,
        self::measurement_system,
        self::collation,
        self::enabled,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::code => PhpType::STRING->value,
        self::language_id => PhpType::INTEGER->value,
        self::country_id => PhpType::INTEGER->value,
        self::date_format => PhpType::STRING->value,
        self::time_format => PhpType::STRING->value,
        self::number_format => PhpType::STRING->value,
        self::phone_format => PhpType::STRING->value,
        self::address_format => PhpType::STRING->value,
        self::measurement_system => PhpType::STRING->value,
        self::collation => PhpType::STRING->value,
        self::enabled => PhpType::BOOLEAN->value,
    ];
    public const array _foreign = [
        self::language_id => Language::_table,
        self::country_id => Country::_table,
    ];
    public const array _indexes = [
        'uk_locale_code' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::code]],
        'uk_locale_pair' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::language_id, self::country_id]],
        'idx_locale_country' => [Entity::INDEX_COLUMNS => [self::country_id, self::id]],
    ];

    public const string _setVia = self::language_id;
    public const bool _setRoot = false;

    public const array _pii = [];
    public const array _piiNotPersonal = [
        self::id,
        self::code,
        self::language_id,
        self::country_id,
        self::date_format,
        self::time_format,
        self::number_format,
        self::phone_format,
        self::address_format,
        self::measurement_system,
        self::collation,
        self::enabled,
    ];

    public ?int $id = null;
    public string $code;
    public int $language_id;
    public ?int $country_id = null;
    public string $date_format;
    public string $time_format;
    public string $number_format;
    public string $phone_format;
    public string $address_format;
    public string $measurement_system;
    public string $collation;
    public bool $enabled = false;
}
