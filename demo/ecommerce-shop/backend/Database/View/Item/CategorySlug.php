<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Object\Item\CategorySlug as ObjectCategorySlug;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * CategorySlug - Db item with high-level abstraction and lazy loading.
 *
 * An address has no writes of its own: the actions of a category record and retire it
 * through the collection.
 *
 * @extends DbItem<ObjectCategorySlug>
 * @method __construct(ObjectCategorySlug $objectCategorySlug)
 *
 * @property-read ?int $id
 * @property-read string $slug
 * @property-read ?int $categoryId Category the address belongs to, null once it is out of use
 */
final class CategorySlug extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name (id, slug, categoryId)
     * @return int|string|null Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): int|string|null
    {
        return match ($name) {
            ObjectCategorySlug::id => $this->_object->id,
            ObjectCategorySlug::slug => $this->_object->slug,
            ObjectCategorySlug::categoryId => $this->_object->categoryId,
            default => parent::__get($name),
        };
    }
}
