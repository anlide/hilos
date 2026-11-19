<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\CategorySlug as EntityCategorySlug;
use Demo\EcommerceShop\Database\Object\Collection\CategorySlugs as ObjectCategorySlugs;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * CategorySlug - Object wrapper for category_slug entity.
 *
 * @extends Object_<EntityCategorySlug>
 *
 * @property-read ?int $id
 * @property string $slug
 * @property ?int $categoryId
 */
final class CategorySlug extends Object_
{
    public const string ENTITY_CLASS = EntityCategorySlug::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCategorySlugs::class;

    public const string id = 'id';
    public const string slug = 'slug';
    public const string categoryId = 'categoryId';

    /**
     * Returns the value of a category address object property by name.
     *
     * @param string $property Property name (id, slug, categoryId)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity or collection access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::slug => $this->entity->slug,
            self::categoryId => $this->entity->category_id,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a category address object property.
     *
     * @param string $property Property name to set (slug, categoryId)
     * @param mixed $value New value; a null categoryId takes the address out of use
     * @throws DatabaseException If entity or collection access fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::slug => $this->entity->slug = (string)$value,
            self::categoryId => $this->entity->category_id = $value === null ? null : (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the category address object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::slug => $this->entity->slug,
            self::categoryId => $this->entity->category_id,
        ];
    }
}
