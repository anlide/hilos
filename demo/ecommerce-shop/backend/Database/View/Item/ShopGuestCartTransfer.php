<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Actions\Item\ShopGuestCartTransferActions;
use Demo\EcommerceShop\Database\Object\Item\ShopGuestCartTransfer as ObjectShopGuestCartTransfer;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * ShopGuestCartTransfer - Db item with high-level abstraction and lazy loading.
 *
 * @extends DbItem<ObjectShopGuestCartTransfer>
 * @method __construct(ObjectShopGuestCartTransfer $objectShopGuestCartTransfer)
 *
 * @property-read ?int $id
 * @property-read int $sessionId Source browser session ID
 * @property-read int $cartEpoch Number of the source cart
 * @property-read int $userId Recipient account ID
 * @property-read bool $received Whether the recipient has saved the cart
 * @property-read ShopGuestCartTransferActions $actions Writes for the recipient's agent
 */
final class ShopGuestCartTransfer extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return mixed Transfer field or inherited item property
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectShopGuestCartTransfer::id => $this->_object->id,
            ObjectShopGuestCartTransfer::sessionId => $this->_object->sessionId,
            ObjectShopGuestCartTransfer::cartEpoch => $this->_object->cartEpoch,
            ObjectShopGuestCartTransfer::userId => $this->_object->userId,
            ObjectShopGuestCartTransfer::received => $this->_object->received,
            default => parent::__get($name),
        };
    }
}
