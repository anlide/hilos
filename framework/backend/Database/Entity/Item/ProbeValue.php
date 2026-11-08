<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\ProbeValues as EntityProbeValues;
use Hilos\Database\PhpType;

/**
 * Synthetic cluster database probe value, owned by the node that writes it.
 *
 * @method static EntityProbeValues get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityProbeValues getAll()
 */
class ProbeValue extends Entity
{
    public const string id = 'id';
    public const string node_id = 'node_id';
    public const string key = 'key';
    public const string value = 'value';

    public const string _table = 'hilos_probe_value';
    public const bool _journaled = false;
    public const string _primary = self::id;
    public const array _columns = [self::id, self::node_id, self::key, self::value];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::node_id => PhpType::STRING->value,
        self::key => PhpType::STRING->value,
        self::value => PhpType::TEXT->value,
    ];
    public const array _indexes = [
        'uk_key' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::key]],
    ];

    // A node id is a soft root: nodes have no persisted parent row.
    public const string _setVia = self::node_id;
    public const bool _setRoot = false;

    public const array _pii = [];
    public const array _piiNotPersonal = [self::id, self::node_id, self::key, self::value];

    public ?int $id = null;
    public string $node_id;
    public string $key;
    public string $value;
}
