<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\ChangeLogFields as EntityChangeLogFields;
use Hilos\Database\PhpType;

/**
 * @method static EntityChangeLogFields get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityChangeLogFields getAll()
 */
class ChangeLogField extends Entity
{
    public const string id = 'id';
    public const string table_id = 'table_id';
    public const string name = 'name';

    public const string _table = 'hilos_change_log_field';
    public const string _database = DatabaseNames::CHANGE_LOG;
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::table_id,
        self::name,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::table_id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
    ];

    public const array _foreign = [self::table_id => ChangeLogTable::_table];

    public const array _indexes = [
        'uk_hcl_field_table_name' => [
            Entity::INDEX_COLUMNS => [self::table_id, self::name],
            Entity::INDEX_UNIQUE => true,
        ],
        'idx_hcl_field_table' => [
            Entity::INDEX_COLUMNS => [self::table_id],
        ],
    ];

    public const bool _journaled = false;
    public const bool _externalWriter = true;
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::table_id,
        self::name,
    ];

    public ?int $id = null;
    public int $table_id;
    public string $name;
}
