<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\I18nReflows as EntityI18nReflows;
use Hilos\Database\PhpType;

/**
 * The fingerprint of the built-in i18n catalog last taken into the reference tables (HIL-1472).
 *
 * One row, id always 1; the database refuses a second one.
 *
 * @method static EntityI18nReflows get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityI18nReflows getAll()
 */
class I18nReflow extends Entity
{
    public const int ROW_ID = 1;

    public const string id = 'id';
    public const string fingerprint = 'fingerprint';

    public const string _table = 'hilos_i18n_reflow';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::fingerprint];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::fingerprint => PhpType::STRING->value,
    ];

    // The record of the installation's catalog belongs to nobody's set.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    public const array _pii = [];
    public const array _piiNotPersonal = [self::id, self::fingerprint];

    public int $id;
    public string $fingerprint;
}
