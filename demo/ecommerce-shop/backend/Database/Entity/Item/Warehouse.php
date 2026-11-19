<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\Warehouses as EntityWarehouses;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * Warehouse - Entity representing warehouse table row.
 *
 * A place the shop keeps its goods in: a name unique across the shop and the address of the
 * premises.
 *
 * @method static EntityWarehouses get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityWarehouses getAll()
 */
final class Warehouse extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string name = 'name';
    public const string address = 'address';

    // Table meta information
    public const string _table = 'warehouse';
    public const string _primary = self::id;
    public const bool _journaled = true;
    public const array _columns = [
        self::id,
        self::name,
        self::address,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::address => PhpType::STRING->value,
    ];

    // Indexes
    public const array _indexes = [
        'uk_warehouse_name' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::name]],
    ];

    // Nobody's set; the stock of a warehouse is to hang its rows on it (HIL-2159).
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = true;

    // The shop's premises, written by an administrator and never shown to a buyer.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::name,
        self::address,
    ];

    // Properties
    public ?int $id = null;
    public string $name;
    public string $address;
}
