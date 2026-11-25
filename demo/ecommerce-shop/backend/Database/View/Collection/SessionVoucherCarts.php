<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\SessionVoucherCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\SessionVoucherCarts as ObjectSessionVoucherCarts;
use Demo\EcommerceShop\Database\View\Item\SessionVoucherCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\HilosException;

/**
 * Voucher bindings keyed by physical binding ID.
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
     * @param int $sessionId Session that holds the voucher slot
     * @param int $cartEpoch Number of the guest cart
     * @return ?int Saved voucher ID, or null for an empty slot
     * @throws DatabaseException When the saved row cannot be read
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function storedVoucherId(int $sessionId, int $cartEpoch): ?int
    {
        return $this->objectCollection->storedVoucherId($sessionId, $cartEpoch);
    }

    /**
     * @param int $bindingId Physical key of a candidate or sparse fact
     * @return ?array{int, int} Saved session and cart epoch, or null when the binding is gone
     * @throws DatabaseException When the saved row cannot be read
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function storedSlotCoordinate(int $bindingId): ?array
    {
        return $this->objectCollection->storedSlotCoordinate($bindingId);
    }

    /**
     * @param list<int> $sessionIds Swept sessions
     * @return array<int, array<int, int>> Physical binding IDs by session and epoch
     * @throws HilosException When the stored rows cannot be selected
     */
    public function storedSlotIdsByPair(array $sessionIds): array
    {
        return $this->objectCollection->storedSlotIdsByPair($sessionIds);
    }

    /**
     * @param int $sessionId Session that holds the voucher slot
     * @param int $cartEpoch Number of the guest cart
     * @return bool Whether the locked pair has a saved voucher
     * @throws NamedDatabaseException When the binding database or table cannot be resolved
     * @throws DatabaseException When the locking read fails
     */
    public function hasLockedSlot(int $sessionId, int $cartEpoch): bool
    {
        return $this->objectCollection->hasLockedSlot($sessionId, $cartEpoch);
    }

    /**
     * Returns the saved binding with its item actions inside the caller's transaction.
     *
     * @param int $sessionId Session that holds the voucher slot
     * @param int $cartEpoch Number of the guest cart
     * @param ?int $candidateId Physical key when rechecking a bounded sweep candidate
     * @return ?SessionVoucherCart Persisted binding, or null for an empty slot
     * @throws NamedDatabaseException When the binding database or table cannot be resolved
     * @throws DatabaseException When the locking read or reconciliation fails
     * @throws InvalidArgumentException When the View item cannot be built
     * @throws LogicException When the collection class constants are not configured
     */
    public function storedSlot(int $sessionId, int $cartEpoch, ?int $candidateId = null): ?SessionVoucherCart
    {
        $binding = $this->objectCollection->loadStoredSlot($sessionId, $cartEpoch, $candidateId);

        return $binding === null ? null : $this->getOrCreateItemForLoadedObject($binding->id, $binding);
    }
}
