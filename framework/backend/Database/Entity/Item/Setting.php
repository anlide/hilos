<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\Settings as EntitySettings;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Setting Entity - represents hilos_setting table row.
 *
 * Key-value settings for Hilos framework.
 * Uses catalog (SettingsCatalogConstants) for type and default_value.
 *
 * @method static EntitySettings get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySettings getAll()
 */
final class Setting extends Entity
{
    public const string id = 'id';
    public const string key = 'key';
    public const string type = 'type';
    public const string value = 'value';

    public const string _table = 'hilos_setting';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::key,
        self::type,
        self::value,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::key => PhpType::STRING->value,
        self::type => PhpType::STRING->value,
        self::value => PhpType::TEXT->value,
    ];

    public const array _indexes = [
        'uk_key' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::key]],
    ];

    public ?int $id = null;
    public string $key;
    public string $type = 'string';
    public ?string $value = null;
}
