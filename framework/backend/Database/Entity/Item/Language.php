<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\Languages as EntityLanguages;
use Hilos\Database\PhpType;

/**
 * A language of the installation's reference catalog.
 *
 * @method static EntityLanguages get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityLanguages getAll()
 */
class Language extends Entity
{
    public const string id = 'id';
    public const string code = 'code';
    public const string native_name = 'native_name';
    public const string rtl = 'rtl';
    public const string enabled = 'enabled';

    public const string _table = 'hilos_language';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::code, self::native_name, self::rtl, self::enabled];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::code => PhpType::STRING->value,
        self::native_name => PhpType::STRING->value,
        self::rtl => PhpType::BOOLEAN->value,
        self::enabled => PhpType::BOOLEAN->value,
    ];
    public const array _indexes = [
        'uk_language_code' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::code]],
    ];

    public const string _setVia = Entity::SET_STANDALONE;
    // A language roots its locales and translated names (HIL-1468).
    public const bool _setRoot = true;

    public const array _pii = [];
    public const array _piiNotPersonal = [self::id, self::code, self::native_name, self::rtl, self::enabled];

    public ?int $id = null;
    public string $code;
    public string $native_name;
    public bool $rtl = false;
    public bool $enabled = false;
}
