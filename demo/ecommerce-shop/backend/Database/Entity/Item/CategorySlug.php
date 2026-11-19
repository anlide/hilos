<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\CategorySlugs as EntityCategorySlugs;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * CategorySlug - Entity representing category_slug table row.
 *
 * An address a category has had at some point, the current one too. A null category_id means
 * the category is deleted and the address is out of use for good.
 *
 * @method static EntityCategorySlugs get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityCategorySlugs getAll()
 */
final class CategorySlug extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string slug = 'slug';
    public const string category_id = 'category_id';

    // Table meta information. The history of renames stays out of the journal: the journal
    // sees a change of address in category.slug.
    public const string _table = 'category_slug';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::slug,
        self::category_id,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::slug => PhpType::STRING->value,
        self::category_id => PhpType::INTEGER->value,
    ];

    // No cascade on category_id: the addresses of a deleted category stay, out of use.
    public const array _foreign = [
        self::category_id => Category::_table,
    ];

    // Indexes
    public const array _indexes = [
        'uk_category_slug_slug' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::slug]],
        'idx_category_slug_category' => [Entity::INDEX_COLUMNS => [self::category_id]],
    ];

    // A nullable owner is no owner: an address outlives its category, so the row is in no set.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    // An address of the shop's catalog; it says nothing about a person.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::slug,
        self::category_id,
    ];

    // Properties
    public ?int $id = null;
    public string $slug;
    public ?int $category_id = null;
}
