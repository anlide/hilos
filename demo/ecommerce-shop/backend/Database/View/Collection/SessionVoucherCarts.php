<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\SessionVoucherCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\SessionVoucherCarts as ObjectSessionVoucherCarts;
use Demo\EcommerceShop\Database\View\Item\SessionVoucherCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Voucher bindings keyed by browser session ID.
 *
 * @extends DbCollection<SessionVoucherCart, ObjectSessionVoucherCarts>
 * @method ObjectSessionVoucherCarts|null getObjectCollection()
 * @method SessionVoucherCart|null first()
 * @method SessionVoucherCart|null last()
 * @method SessionVoucherCart offsetGet(mixed $offset)
 * @property-read SessionVoucherCartsActions $actions Creation for the guest carts library
 */
final class SessionVoucherCarts extends DbCollection
{
    public const string DB_ITEM_CLASS = SessionVoucherCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessionVoucherCarts::class;

    /**
     * Returns the saved binding with its item actions inside the caller's transaction.
     *
     * @param int $sessionId Session that holds the voucher slot
     * @return ?SessionVoucherCart Persisted binding, or null for an empty slot
     * @throws DatabaseException When the keyed read or reconciliation fails
     * @throws InvalidArgumentException When the entity query or View item cannot be built
     * @throws LogicException When the collection class constants are not configured
     * @throws SourceChangeSubscriberException When a subscriber to lost membership fails
     */
    public function storedSlot(int $sessionId): ?SessionVoucherCart
    {
        $binding = $this->objectCollection->loadStoredSlot($sessionId);

        return $binding === null ? null : $this->getOrCreateItemForLoadedObject($sessionId, $binding);
    }
}
