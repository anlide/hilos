<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\StatusObservedComponents as EntityStatusObservedComponents;
use Hilos\Database\PhpType;

/**
 * The moment each Status component came under observation; a row is written once and never changes.
 *
 * @method static EntityStatusObservedComponents get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityStatusObservedComponents getAll()
 */
class StatusObservedComponent extends Entity
{
    public const string id = 'id';
    public const string component_key = 'component_key';
    public const string observed_since = 'observed_since';

    public const string _table = 'hilos_status_observed_component';
    public const string _database = DatabaseNames::PRIMARY;
    public const bool _journaled = false;
    public const string _primary = self::id;
    public const array _columns = [self::id, self::component_key, self::observed_since];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::component_key => PhpType::STRING->value,
        self::observed_since => PhpType::INTEGER->value,
    ];
    public const array _moments = [self::observed_since];
    public const array _indexes = [
        'uk_status_observed_component_key' => [
            Entity::INDEX_COLUMNS => [self::component_key],
            Entity::INDEX_UNIQUE => true,
        ],
    ];
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;
    public const array _pii = [];
    public const array _piiNotPersonal = self::_columns;

    public ?int $id = null;
    public string $component_key;
    public int $observed_since;
}
