<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * SessionProductCart - Db item with high-level abstraction and lazy loading.
 *
 * A line of a guest's cart has no writes of its own: the guest carts library puts a product in,
 * changes its quantity and takes it out through the collection.
 *
 * @extends DbItem<ObjectSessionProductCart>
 * @method __construct(ObjectSessionProductCart $objectSessionProductCart)
 *
 * @property-read ?int $id
 * @property-read int $sessionId Number of the browser session whose cart it is
 * @property-read int $productId Product in the cart
 * @property-read int $quantity How many units, one at least
 */
final class SessionProductCart extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name (id, sessionId, productId, quantity)
     * @return int|null Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): ?int
    {
        return match ($name) {
            ObjectSessionProductCart::id => $this->_object->id,
            ObjectSessionProductCart::sessionId => $this->_object->sessionId,
            ObjectSessionProductCart::productId => $this->_object->productId,
            ObjectSessionProductCart::quantity => $this->_object->quantity,
            default => parent::__get($name),
        };
    }
}
