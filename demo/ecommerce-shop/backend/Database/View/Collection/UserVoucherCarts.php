<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\UserVoucherCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\UserVoucherCarts as ObjectUserVoucherCarts;
use Demo\EcommerceShop\Database\View\Item\UserVoucherCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\HilosException;

/**
 * Voucher bindings keyed by person ID.
 *
 * @extends DbCollection<UserVoucherCart, ObjectUserVoucherCarts>
 * @method ObjectUserVoucherCarts|null getObjectCollection()
 * @method UserVoucherCart|null first()
 * @method UserVoucherCart|null last()
 * @method UserVoucherCart offsetGet(mixed $offset)
 * @property-read UserVoucherCartsActions $actions Creation for the person's agent
 */
final class UserVoucherCarts extends DbCollection
{
    public const string DB_ITEM_CLASS = UserVoucherCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserVoucherCarts::class;

    /**
     * @param int $userId Cart owner
     * @return ?int Saved voucher ID, or null for an empty slot
     * @throws DatabaseException When the saved row cannot be read
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function storedVoucherId(int $userId): ?int
    {
        return $this->objectCollection->storedVoucherId($userId);
    }

    /**
     * @param int $userId Cart owner already running in PRIMARY
     * @return ?UserVoucherCart Saved binding with its item actions, or null for an empty slot
     * @throws HilosException When the transaction, store or View refuses the read
     */
    public function storedSlot(int $userId): ?UserVoucherCart
    {
        $binding = $this->objectCollection->loadStoredSlot($userId);

        return $binding === null ? null : $this->getOrCreateItemForLoadedObject($userId, $binding);
    }
}
