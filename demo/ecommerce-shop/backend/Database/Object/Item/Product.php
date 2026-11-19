<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Object\Collection\Products as ObjectProducts;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * Product - Object wrapper for product entity.
 *
 * @extends Object_<EntityProduct>
 *
 * @property-read ?int $id
 * @property string $name
 * @property int $categoryId
 * @property string $kind Value of the kind column, one of ProductKind
 * @property int $price Price in kopecks
 * @property string $description
 * @property int $pictureFileId
 * @property string $slug
 * @property int $changedAt Moment of the last edit, epoch milliseconds
 */
final class Product extends Object_
{
    public const string ENTITY_CLASS = EntityProduct::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectProducts::class;

    public const string id = 'id';
    public const string name = 'name';
    public const string categoryId = 'categoryId';
    public const string kind = 'kind';
    public const string price = 'price';
    public const string description = 'description';
    public const string pictureFileId = 'pictureFileId';
    public const string slug = 'slug';
    public const string changedAt = 'changedAt';

    /**
     * Returns the value of a product object property by name.
     *
     * @param string $property Property name (id, name, categoryId, kind, price, description, pictureFileId, slug, changedAt)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity or collection access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::categoryId => $this->entity->category_id,
            self::kind => $this->entity->kind,
            self::price => $this->entity->price,
            self::description => $this->entity->description,
            self::pictureFileId => $this->entity->picture_file_id,
            self::slug => $this->entity->slug,
            self::changedAt => $this->entity->changed_at,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a product object property.
     *
     * @param string $property Property name to set (name, categoryId, kind, price, description, pictureFileId, slug, changedAt)
     * @param mixed $value New value
     * @throws DatabaseException If entity or collection access fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::name => $this->entity->name = (string)$value,
            self::categoryId => $this->entity->category_id = (int)$value,
            self::kind => $this->entity->kind = (string)$value,
            self::price => $this->entity->price = (int)$value,
            self::description => $this->entity->description = (string)$value,
            self::pictureFileId => $this->entity->picture_file_id = (int)$value,
            self::slug => $this->entity->slug = (string)$value,
            self::changedAt => $this->entity->changed_at = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the product object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::categoryId => $this->entity->category_id,
            self::kind => $this->entity->kind,
            self::price => $this->entity->price,
            self::description => $this->entity->description,
            self::pictureFileId => $this->entity->picture_file_id,
            self::slug => $this->entity->slug,
            self::changedAt => $this->entity->changed_at,
        ];
    }
}
