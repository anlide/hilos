<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\StatusWorkComponents as EntityStatusWorkComponents;
use Hilos\Database\PhpType;

/**
 * Durable Status storage owned by the existing cluster Status agent.
 *
 * @method static EntityStatusWorkComponents get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityStatusWorkComponents getAll()
 */
class StatusWorkComponent extends Entity
{
    public const string id = 'id';
    public const string work_id = 'work_id';
    public const string component_key = 'component_key';

    public const string _table = 'hilos_status_work_component';
    public const string _database = DatabaseNames::PRIMARY;
    public const bool _journaled = false;
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::work_id,
        self::component_key,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::work_id => PhpType::INTEGER->value,
        self::component_key => PhpType::STRING->value,
    ];
    public const array _foreign = [self::work_id => StatusWork::_table];
    public const array _indexes = [
        'uk_status_work_component' => [
            Entity::INDEX_COLUMNS => [
                self::work_id,
                self::component_key,
            ],
            Entity::INDEX_UNIQUE => true,
        ],
        'idx_status_work_component_key' => [
            Entity::INDEX_COLUMNS => [
                self::component_key,
                self::work_id,
                self::id,
            ],
        ],
    ];
    public const string _setVia = self::work_id;
    public const bool _setRoot = false;
    public const array _pii = [];
    public const array _piiNotPersonal = self::_columns;

    public ?int $id = null;
    public int $work_id;
    public string $component_key;
}
