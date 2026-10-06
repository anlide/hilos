<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\LanguageNames as EntityLanguageNames;
use Hilos\Database\PhpType;

/**
 * A translated language name, optionally overridden for a locale.
 *
 * @method static EntityLanguageNames get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityLanguageNames getAll()
 */
class LanguageName extends Entity
{
    public const int NAME_MAX_CHARS = 255;

    public const string id = 'id';
    public const string language_id = 'language_id';
    public const string in_language_id = 'in_language_id';
    public const string locale_id = 'locale_id';
    public const string locale_slot = 'locale_slot';
    public const string name = 'name';
    public const string locked = 'locked';

    public const string _table = 'hilos_language_name';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::language_id, self::in_language_id, self::locale_id, self::name, self::locked];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::language_id => PhpType::INTEGER->value,
        self::in_language_id => PhpType::INTEGER->value,
        self::locale_id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::locked => PhpType::BOOLEAN->value,
    ];
    public const array _foreign = [
        self::language_id => Language::_table,
        self::in_language_id => Language::_table,
        self::locale_id => Locale::_table,
    ];
    public const array _indexes = [
        'uk_language_name_slot' => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::language_id, self::in_language_id, self::locale_slot],
        ],
        'idx_language_name_in_language_id' => [Entity::INDEX_COLUMNS => [self::in_language_id]],
        'idx_language_name_locale' => [Entity::INDEX_COLUMNS => [self::locale_id]],
    ];

    public const string _setVia = self::language_id;
    public const bool _setRoot = false;

    public const array _pii = [];
    public const array _piiNotPersonal = [
        self::id, self::language_id, self::in_language_id, self::locale_id, self::locale_slot, self::name, self::locked,
    ];

    public ?int $id = null;
    public int $language_id;
    public int $in_language_id;
    public ?int $locale_id = null;
    public string $name;
    public bool $locked = false;
}
