<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\Products as EntityProducts;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\File;
use Hilos\Database\PhpType;

/**
 * Product - Entity representing product table row.
 *
 * An item of the shop's catalog: its name, the category it is in, its kind, its price in
 * kopecks, a text describing it, its picture in the files registry, the address it is reached
 * by and the moment it was last edited.
 *
 * @method static EntityProducts get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityProducts getAll()
 */
final class Product extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string name = 'name';
    public const string category_id = 'category_id';
    public const string kind = 'kind';
    public const string price = 'price';
    public const string description = 'description';
    public const string picture_file_id = 'picture_file_id';
    public const string slug = 'slug';
    public const string changed_at = 'changed_at';

    // Table meta information
    public const string _table = 'product';
    public const string _primary = self::id;
    public const bool _journaled = true;
    public const array _journalNoise = [self::changed_at => 'The time of the last edit; every journal line carries its own time'];
    public const array _columns = [
        self::id,
        self::name,
        self::category_id,
        self::kind,
        self::price,
        self::description,
        self::picture_file_id,
        self::slug,
        self::changed_at,
    ];

    public const array _moments = [self::changed_at];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::category_id => PhpType::INTEGER->value,
        self::kind => PhpType::STRING->value,
        self::price => PhpType::INTEGER->value,
        self::description => PhpType::STRING->value,
        self::picture_file_id => PhpType::INTEGER->value,
        self::slug => PhpType::STRING->value,
        self::changed_at => PhpType::INTEGER->value,
    ];

    // No cascade on either: the database refuses to remove a category or a file a product still
    // names, which is also how the files library learns the shop still links the file.
    public const array _foreign = [
        self::category_id => Category::_table,
        self::picture_file_id => File::_table,
    ];

    // Indexes
    public const array _indexes = [
        'uk_product_slug' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::slug]],
        'uk_product_picture' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::picture_file_id]],
        'idx_product_category' => [Entity::INDEX_COLUMNS => [self::category_id]],
        'idx_product_changed' => [Entity::INDEX_COLUMNS => [self::changed_at]],
    ];

    // A root: the stock of a product and the cart lines are to hang on it (HIL-2159, HIL-2149).
    // It is in no category's set; its category changes by an edit.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = true;

    // The shop's catalog, written by an administrator; it says nothing about a person.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::name,
        self::category_id,
        self::kind,
        self::price,
        self::description,
        self::picture_file_id,
        self::slug,
        self::changed_at,
    ];

    // Properties
    public ?int $id = null;
    public string $name;
    public int $category_id;
    public string $kind;
    public int $price;
    public string $description;
    public int $picture_file_id;
    public string $slug;
    public int $changed_at;
}
