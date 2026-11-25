<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Actions\Item\SessionVoucherCartActions;
use Demo\EcommerceShop\Database\Object\Item\SessionVoucherCart as ObjectSessionVoucherCart;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectSessionVoucherCart>
 * @property-read ?int $id Persisted binding ID
 * @property-read int $sessionId Owner of the cart
 * @property-read int $cartEpoch Number of the guest cart
 * @property-read int $voucherId Voucher selected for the cart
 * @property-read SessionVoucherCartActions $actions Writes for the guest carts library
 */
final class SessionVoucherCart extends DbItem
{
    /**
     * @param string $name Property name
     * @return mixed Binding field or inherited item property
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions cannot be resolved
     * @throws HilosException When inherited property access fails
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectSessionVoucherCart::id => $this->_object->id,
            ObjectSessionVoucherCart::sessionId => $this->_object->sessionId,
            ObjectSessionVoucherCart::cartEpoch => $this->_object->cartEpoch,
            ObjectSessionVoucherCart::voucherId => $this->_object->voucherId,
            default => parent::__get($name),
        };
    }
}
