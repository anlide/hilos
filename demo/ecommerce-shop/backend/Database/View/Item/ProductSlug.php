<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Object\Item\ProductSlug as ObjectProductSlug;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * ProductSlug - Db item with high-level abstraction and lazy loading.
 *
 * An address has no writes of its own: the actions of a product record it through the
 * collection.
 *
 * @extends DbItem<ObjectProductSlug>
 * @method __construct(ObjectProductSlug $objectProductSlug)
 *
 * @property-read ?int $id
 * @property-read string $slug
 * @property-read int $productId Product the address leads to
 */
final class ProductSlug extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name (id, slug, productId)
     * @return int|string|null Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): int|string|null
    {
        return match ($name) {
            ObjectProductSlug::id => $this->_object->id,
            ObjectProductSlug::slug => $this->_object->slug,
            ObjectProductSlug::productId => $this->_object->productId,
            default => parent::__get($name),
        };
    }
}
