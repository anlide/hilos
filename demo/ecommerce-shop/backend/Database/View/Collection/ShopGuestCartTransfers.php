<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\ShopGuestCartTransfersActions;
use Demo\EcommerceShop\Database\Object\Collection\ShopGuestCartTransfers as ObjectShopGuestCartTransfers;
use Demo\EcommerceShop\Database\View\Item\ShopGuestCartTransfer;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\HilosException;

/**
 * Guest cart transfers keyed by their primary ID.
 *
 * @extends DbCollection<ShopGuestCartTransfer, ObjectShopGuestCartTransfers>
 * @method ObjectShopGuestCartTransfers|null getObjectCollection()
 * @method ShopGuestCartTransfer|null current()
 * @method ShopGuestCartTransfer|null first()
 * @method ShopGuestCartTransfer|null last()
 * @method ShopGuestCartTransfer offsetGet(mixed $offset)
 * @property-read ShopGuestCartTransfersActions $actions
 */
final class ShopGuestCartTransfers extends DbCollection
{
    public const string DB_ITEM_CLASS = ShopGuestCartTransfer::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectShopGuestCartTransfers::class;

    /**
     * @param int $transferId Saved transfer key
     * @return ?array{int, int, int, bool} Source session and epoch, assigned user, and saved receipt
     * @throws DatabaseException When the row cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedTransfer(int $transferId): ?array
    {
        return $this->objectCollection->storedTransfer($transferId);
    }

    /**
     * Locks a fresh assignment in PRIMARY even if its creation or receipt has yet to reach this worker.
     *
     * @param int $transferId Saved transfer key
     * @param int $userId Expected recipient
     * @return ?ShopGuestCartTransfer Assignment with its item actions, or null when absent or assigned elsewhere
     * @throws HilosException When PRIMARY, the locking read or View item cannot be resolved
     */
    public function storedTransferForRecipient(int $transferId, int $userId): ?ShopGuestCartTransfer
    {
        $transfer = $this->objectCollection->loadStoredForRecipient($transferId, $userId);

        return $transfer === null ? null : $this->getOrCreateItemForLoadedObject($transferId, $transfer);
    }

    /**
     * @param int $userId Assigned recipient whose account row is already locked
     * @return bool Whether PRIMARY holds a still-unreceived assignment for this person
     * @throws HilosException When PRIMARY is not open or the locking read fails
     */
    public function hasUnreceivedForUser(int $userId): bool
    {
        return $this->objectCollection->hasUnreceivedForUser($userId);
    }

    /**
     * @param int $userId Recipient who owns the updates
     * @param ?int $beforeTransferId Exclusive upper bound, or null to keep all pending choices in scope
     * @return list<int> Keys for item access to locked pending assignments whose vouchers can still be superseded
     * @throws HilosException When the locking read or reconciliation fails
     */
    public function lockPendingVoucherTransferIds(int $userId, ?int $beforeTransferId): array
    {
        return $this->objectCollection->lockPendingVoucherTransferIds($userId, $beforeTransferId);
    }

    /**
     * @return int Highest durable transfer ID at the start of the pass, zero for an empty table
     * @throws DatabaseException When the rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function latestStoredTransferId(): int
    {
        return $this->objectCollection->latestStoredTransferId();
    }

    /**
     * @param int $limit Maximum number of saved transfer keys to read
     * @param int $afterId Exclusive recovery cursor
     * @param int $throughId Inclusive recovery high-water mark
     * @return list<int> Durable transfer keys ordered by ID
     * @throws DatabaseException When the rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedTransferIdsAfter(int $limit, int $afterId, int $throughId): array
    {
        return $this->objectCollection->storedTransferIds($limit, null, $afterId, $throughId);
    }

    /**
     * @param int $sessionId Source session for the wake
     * @param int $limit Maximum number of saved transfer keys to read
     * @return list<int> Durable transfer keys for this session ordered by epoch
     * @throws DatabaseException When the rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedTransferIdsForSession(int $sessionId, int $limit): array
    {
        return $this->objectCollection->storedTransferIds($limit, $sessionId, 0, 0);
    }

    /**
     * @param int $transferId Saved transfer key
     * @param int $userId Expected recipient
     * @return ?bool Saved received value, or null when the transfer is absent or assigned elsewhere
     * @throws DatabaseException When the row cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedReceipt(int $transferId, int $userId): ?bool
    {
        return $this->objectCollection->storedReceipt($transferId, $userId);
    }
}
