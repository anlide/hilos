<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\ChangeLogTables as EntityChangeLogTables;
use Hilos\Database\PhpType;

/**
 * @method static EntityChangeLogTables get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityChangeLogTables getAll()
 */
class ChangeLogTable extends Entity
{
    public const string id = 'id';
    public const string name = 'name';

    public const string _table = 'hilos_change_log_table';
    public const string _database = DatabaseNames::CHANGE_LOG;
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::name,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
    ];

    public const array _indexes = [
        'uk_hcl_table_name' => [
            Entity::INDEX_COLUMNS => [self::name],
            Entity::INDEX_UNIQUE => true,
        ],
    ];

    public const bool _journaled = false;
    public const bool _externalWriter = true;
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::name,
    ];

    public ?int $id = null;
    public string $name;
}
