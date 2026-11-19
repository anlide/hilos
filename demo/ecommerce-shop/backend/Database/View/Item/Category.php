<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Actions\Item\CategoryActions;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * Category - Db item with high-level abstraction and lazy loading.
 *
 * @extends DbItem<ObjectCategory>
 * @method __construct(ObjectCategory $objectCategory)
 *
 * @property-read ?int $id
 * @property-read string $name
 * @property-read string $description
 * @property-read string $slug
 * @property-read int $position
 * @property-read int $pictureFileId
 * @property-read CategoryActions $actions Item-level write operations
 */
final class Category extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name (id, name, description, slug, position, pictureFileId, actions)
     * @return int|string|CategoryActions|null Property value or actions
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): int|string|CategoryActions|null
    {
        return match ($name) {
            ObjectCategory::id => $this->_object->id,
            ObjectCategory::name => $this->_object->name,
            ObjectCategory::description => $this->_object->description,
            ObjectCategory::slug => $this->_object->slug,
            ObjectCategory::position => $this->_object->position,
            ObjectCategory::pictureFileId => $this->_object->pictureFileId,
            default => parent::__get($name),
        };
    }
}
