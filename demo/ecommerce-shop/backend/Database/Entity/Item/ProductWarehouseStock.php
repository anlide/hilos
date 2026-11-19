<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\ProductWarehouseStocks as EntityProductWarehouseStocks;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * ProductWarehouseStock - Entity representing product_warehouse_stock table row.
 *
 * How many units of a product one warehouse of the shop keeps. A row holds one unit at least:
 * a product a warehouse does not keep has no row there.
 *
 * @method static EntityProductWarehouseStocks get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityProductWarehouseStocks getAll()
 */
final class ProductWarehouseStock extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string product_id = 'product_id';
    public const string warehouse_id = 'warehouse_id';
    public const string units = 'units';

    // Table meta information
    public const string _table = 'product_warehouse_stock';
    public const string _primary = self::id;
    public const bool _journaled = true;
    public const array _columns = [
        self::id,
        self::product_id,
        self::warehouse_id,
        self::units,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::product_id => PhpType::INTEGER->value,
        self::warehouse_id => PhpType::INTEGER->value,
        self::units => PhpType::INTEGER->value,
    ];

    // No cascade on either: the database refuses to remove a product or a warehouse a row still
    // names.
    public const array _foreign = [
        self::product_id => Product::_table,
        self::warehouse_id => Warehouse::_table,
    ];

    // Indexes
    public const array _indexes = [
        'uk_product_warehouse_stock' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::product_id, self::warehouse_id]],
        'idx_product_warehouse_stock_warehouse' => [Entity::INDEX_COLUMNS => [self::warehouse_id]],
    ];

    // The stock of a product, so the row is in the product's set; a warehouse is no set.
    public const string _setVia = self::product_id;
    public const bool _setRoot = false;

    // How much of a product a warehouse of the shop keeps; it says nothing about a person.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::product_id,
        self::warehouse_id,
        self::units,
    ];

    // Properties
    public ?int $id = null;
    public int $product_id;
    public int $warehouse_id;
    public int $units;
}
