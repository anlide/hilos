<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Object\Collection\Categories as ObjectCategories;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * Category - Object wrapper for category entity.
 *
 * @extends Object_<EntityCategory>
 *
 * @property-read ?int $id
 * @property string $name
 * @property string $description
 * @property string $slug
 * @property int $position
 * @property int $pictureFileId
 */
final class Category extends Object_
{
    public const string ENTITY_CLASS = EntityCategory::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCategories::class;

    public const string id = 'id';
    public const string name = 'name';
    public const string description = 'description';
    public const string slug = 'slug';
    public const string position = 'position';
    public const string pictureFileId = 'pictureFileId';

    /**
     * Returns the value of a category object property by name.
     *
     * @param string $property Property name (id, name, description, slug, position, pictureFileId)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity or collection access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::description => $this->entity->description,
            self::slug => $this->entity->slug,
            self::position => $this->entity->position,
            self::pictureFileId => $this->entity->picture_file_id,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a category object property.
     *
     * @param string $property Property name to set (name, description, slug, position, pictureFileId)
     * @param mixed $value New value
     * @throws DatabaseException If entity or collection access fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::name => $this->entity->name = (string)$value,
            self::description => $this->entity->description = (string)$value,
            self::slug => $this->entity->slug = (string)$value,
            self::position => $this->entity->position = (int)$value,
            self::pictureFileId => $this->entity->picture_file_id = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the category object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::description => $this->entity->description,
            self::slug => $this->entity->slug,
            self::position => $this->entity->position,
            self::pictureFileId => $this->entity->picture_file_id,
        ];
    }
}
