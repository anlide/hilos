<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Actions\Item\SessionProductCartActions;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * SessionProductCart - Db item with high-level abstraction and lazy loading.
 *
 * @extends DbItem<ObjectSessionProductCart>
 * @method __construct(ObjectSessionProductCart $objectSessionProductCart)
 *
 * @property-read ?int $id
 * @property-read int $sessionId Number of the browser session whose cart it is
 * @property-read int $productId Product in the cart
 * @property-read int $quantity How many units, one at least
 * @property-read SessionProductCartActions $actions Writes for the guest carts library
 */
final class SessionProductCart extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return mixed Line field or inherited item property
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
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
