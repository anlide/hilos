<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * UserProductCart - Db item with high-level abstraction and lazy loading.
 *
 * A line of a person's cart has no writes of its own: the person's agent puts a product in,
 * changes its quantity and takes it out through the collection.
 *
 * @extends DbItem<ObjectUserProductCart>
 * @method __construct(ObjectUserProductCart $objectUserProductCart)
 *
 * @property-read ?int $id
 * @property-read int $userId Person whose cart it is
 * @property-read int $productId Product in the cart
 * @property-read int $quantity How many units, one at least
 */
final class UserProductCart extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name (id, userId, productId, quantity)
     * @return int|null Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): ?int
    {
        return match ($name) {
            ObjectUserProductCart::id => $this->_object->id,
            ObjectUserProductCart::userId => $this->_object->userId,
            ObjectUserProductCart::productId => $this->_object->productId,
            ObjectUserProductCart::quantity => $this->_object->quantity,
            default => parent::__get($name),
        };
    }
}
