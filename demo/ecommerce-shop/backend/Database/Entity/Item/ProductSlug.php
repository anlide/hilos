<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\ProductSlugs as EntityProductSlugs;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * ProductSlug - Entity representing product_slug table row.
 *
 * An address a product has had at some point, the current one too. A product is never
 * deleted, so every address has its product.
 *
 * @method static EntityProductSlugs get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityProductSlugs getAll()
 */
final class ProductSlug extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string slug = 'slug';
    public const string product_id = 'product_id';

    // Table meta information. The history of renames stays out of the journal: the journal
    // sees a change of address in product.slug.
    public const string _table = 'product_slug';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::slug,
        self::product_id,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::slug => PhpType::STRING->value,
        self::product_id => PhpType::INTEGER->value,
    ];

    public const array _foreign = [
        self::product_id => Product::_table,
    ];

    // Indexes
    public const array _indexes = [
        'uk_product_slug_slug' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::slug]],
        'idx_product_slug_product' => [Entity::INDEX_COLUMNS => [self::product_id]],
    ];

    // An address belongs to its product for good, so the row is in the product's set.
    public const string _setVia = self::product_id;
    public const bool _setRoot = false;

    // An address of the shop's catalog; it says nothing about a person.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::slug,
        self::product_id,
    ];

    // Properties
    public ?int $id = null;
    public string $slug;
    public int $product_id;
}
