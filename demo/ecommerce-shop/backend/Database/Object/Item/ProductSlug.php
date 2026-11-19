<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\ProductSlug as EntityProductSlug;
use Demo\EcommerceShop\Database\Object\Collection\ProductSlugs as ObjectProductSlugs;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * ProductSlug - Object wrapper for product_slug entity.
 *
 * @extends Object_<EntityProductSlug>
 *
 * @property-read ?int $id
 * @property string $slug
 * @property int $productId
 */
final class ProductSlug extends Object_
{
    public const string ENTITY_CLASS = EntityProductSlug::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectProductSlugs::class;

    public const string id = 'id';
    public const string slug = 'slug';
    public const string productId = 'productId';

    /**
     * Returns the value of a product address object property by name.
     *
     * @param string $property Property name (id, slug, productId)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity or collection access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::slug => $this->entity->slug,
            self::productId => $this->entity->product_id,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a product address object property.
     *
     * @param string $property Property name to set (slug, productId)
     * @param mixed $value New value
     * @throws DatabaseException If entity or collection access fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::slug => $this->entity->slug = (string)$value,
            self::productId => $this->entity->product_id = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the product address object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::slug => $this->entity->slug,
            self::productId => $this->entity->product_id,
        ];
    }
}
