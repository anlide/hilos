<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\StatusMaintenancePeriods as EntityStatusMaintenancePeriods;
use Hilos\Database\PhpType;
use Hilos\Database\SqlSortDirection;

/**
 * Durable Status storage owned by the existing cluster Status agent.
 *
 * @method static EntityStatusMaintenancePeriods get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityStatusMaintenancePeriods getAll()
 */
class StatusMaintenancePeriod extends Entity
{
    public const string id = 'id';
    public const string public_id = 'public_id';
    public const string work_id = 'work_id';
    public const string work_public_id = 'work_public_id';
    public const string announcement_known = 'announcement_known';
    public const string title = 'title';
    public const string component_keys_json = 'component_keys_json';
    public const string started_at = 'started_at';
    public const string last_observed_at = 'last_observed_at';
    public const string ended_at = 'ended_at';
    public const string completion_observed_at = 'completion_observed_at';

    public const string _table = 'hilos_status_maintenance_period';
    public const string _database = DatabaseNames::PRIMARY;
    public const bool _journaled = false;
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::public_id,
        self::work_id,
        self::work_public_id,
        self::announcement_known,
        self::title,
        self::component_keys_json,
        self::started_at,
        self::last_observed_at,
        self::ended_at,
        self::completion_observed_at,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::public_id => PhpType::STRING->value,
        self::work_id => PhpType::INTEGER->value,
        self::work_public_id => PhpType::STRING->value,
        self::announcement_known => PhpType::BOOLEAN->value,
        self::title => PhpType::STRING->value,
        self::component_keys_json => PhpType::TEXT->value,
        self::started_at => PhpType::INTEGER->value,
        self::last_observed_at => PhpType::INTEGER->value,
        self::ended_at => PhpType::INTEGER->value,
        self::completion_observed_at => PhpType::INTEGER->value,
    ];
    public const array _foreign = [self::work_id => StatusWork::_table];
    public const array _indexes = [
        'uk_status_maintenance_period_public' => [
            Entity::INDEX_COLUMNS => [
                self::public_id,
            ],
            Entity::INDEX_UNIQUE => true,
        ],
        'idx_status_maintenance_period_started' => [
            Entity::INDEX_COLUMNS => [
                [Entity::INDEX_COLUMN => self::started_at, Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
                [Entity::INDEX_COLUMN => self::id, Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
            ],
        ],
        'idx_status_maintenance_period_completion' => [
            Entity::INDEX_COLUMNS => [
                self::completion_observed_at,
                self::id,
            ],
        ],
        'idx_status_maintenance_period_work' => [
            Entity::INDEX_COLUMNS => [
                self::work_id,
                self::id,
            ],
        ],
    ];
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;
    public const array _pii = [];
    public const array _piiNotPersonal = self::_columns;

    public ?int $id = null;
    public string $public_id;
    public ?int $work_id = null;
    public ?string $work_public_id = null;
    public bool $announcement_known;
    public string $title;
    public string $component_keys_json;
    public int $started_at;
    public int $last_observed_at;
    public ?int $ended_at = null;
    public ?int $completion_observed_at = null;
}
