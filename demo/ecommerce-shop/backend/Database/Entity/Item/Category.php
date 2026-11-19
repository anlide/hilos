<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Item;

use Demo\EcommerceShop\Database\Entity\Collection\Categories as EntityCategories;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\File;
use Hilos\Database\PhpType;

/**
 * Category - Entity representing category table row.
 *
 * A section of the shop's catalog: a name unique across the shop, a line describing it, the
 * address it is reached by, its place in the catalog's order and its picture in the files
 * registry.
 *
 * @method static EntityCategories get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityCategories getAll()
 */
final class Category extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string name = 'name';
    public const string description = 'description';
    public const string slug = 'slug';
    public const string position = 'position';
    public const string picture_file_id = 'picture_file_id';

    // Table meta information
    public const string _table = 'category';
    public const string _primary = self::id;
    public const bool _journaled = true;
    public const array _columns = [
        self::id,
        self::name,
        self::description,
        self::slug,
        self::position,
        self::picture_file_id,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::name => PhpType::STRING->value,
        self::description => PhpType::STRING->value,
        self::slug => PhpType::STRING->value,
        self::position => PhpType::INTEGER->value,
        self::picture_file_id => PhpType::INTEGER->value,
    ];

    // No cascade on picture_file_id: the refusal to remove a file a category still names is how
    // the files library learns the shop still links it.
    public const array _foreign = [
        self::picture_file_id => File::_table,
    ];

    // Indexes
    public const array _indexes = [
        'uk_category_name' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::name]],
        'uk_category_slug' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::slug]],
        'uk_category_picture' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::picture_file_id]],
        'idx_category_position' => [Entity::INDEX_COLUMNS => [self::position]],
    ];

    // Nobody's set. A product names its category but is a root of its own (HIL-2158).
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = true;

    // The shop's catalog, written by an administrator; it says nothing about a person.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::id,
        self::name,
        self::description,
        self::slug,
        self::position,
        self::picture_file_id,
    ];

    // Properties
    public ?int $id = null;
    public string $name;
    public string $description;
    public string $slug;
    public int $position;
    public int $picture_file_id;
}
