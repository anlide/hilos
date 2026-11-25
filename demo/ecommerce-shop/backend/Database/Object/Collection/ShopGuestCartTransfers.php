<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\ShopGuestCartTransfers as EntityShopGuestCartTransfers;
use Demo\EcommerceShop\Database\Entity\Item\ShopGuestCartTransfer as EntityShopGuestCartTransfer;
use Demo\EcommerceShop\Database\Object\Item\ShopGuestCartTransfer as ObjectShopGuestCartTransfer;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;

/**
 * Store of guest cart transfers, loaded by key outside the recipient's own set.
 *
 * @extends Objects<ObjectShopGuestCartTransfer>
 * @method ObjectShopGuestCartTransfer|null current()
 * @method ObjectShopGuestCartTransfer|null first()
 * @method ObjectShopGuestCartTransfer|null last()
 * @method ObjectShopGuestCartTransfer|null get(int|string $key)
 * @method ObjectShopGuestCartTransfer|null offsetGet(mixed $offset)
 */
final class ShopGuestCartTransfers extends Objects
{
    public const string OBJECT_CLASS = ObjectShopGuestCartTransfer::class;
    public const string ENTITY_COLLECTION_CLASS = EntityShopGuestCartTransfers::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::shopGuestCartTransfers;

    /**
     * @param int $transferId Saved transfer key
     * @return ?array{int, int, int, bool} Source session and epoch, assigned user, and saved receipt
     * @throws DatabaseException When the row cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedTransfer(int $transferId): ?array
    {
        $transfer = EntityShopGuestCartTransfer::getById($transferId);

        return $transfer === null ? null : [$transfer->session_id, $transfer->cart_epoch, $transfer->user_id, $transfer->received];
    }

    /**
     * Loads the assigned row and gives its item action the same saved baseline.
     *
     * @param int $transferId Saved transfer key
     * @param int $userId Expected recipient
     * @return ?ObjectShopGuestCartTransfer Fresh row, or null when absent or assigned elsewhere
     * @throws DatabaseException When the row or its database cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function loadStoredForRecipient(int $transferId, int $userId): ?ObjectShopGuestCartTransfer
    {
        $stored = EntityShopGuestCartTransfer::getById($transferId);
        if ($stored?->user_id !== $userId) {
            return null;
        }

        return $this->holdStored($stored);
    }

    /**
     * Locks the recipient's pending choices against concurrent assignments.
     *
     * @param int $userId Recipient who owns the updates
     * @param ?int $beforeTransferId Exclusive upper bound for a choice from another transfer
     * @return list<int> Keys of locked and refreshed pending transfers whose vouchers can still be superseded
     * @throws HilosException When the locking read or reconciliation fails
     */
    public function lockPendingVoucherTransferIds(int $userId, ?int $beforeTransferId): array
    {
        $index = EntityShopGuestCartTransfer::getDatabase()->connectionIndex;
        if (Database::runtimeTransactionLevel($index) === null) {
            throw new DatabaseException('Superseding guest vouchers requires a PRIMARY transaction.');
        }

        $sql = 'SELECT * FROM ' . EntityShopGuestCartTransfer::getSqlTableName() . ' FORCE INDEX (idx_shop_guest_cart_transfer_user)'
            . ' WHERE `user_id` = ? AND `received` = FALSE AND `voucher_superseded` = FALSE';
        $params = [$userId];
        if ($beforeTransferId !== null) {
            $sql .= ' AND `id` < ?';
            $params[] = $beforeTransferId;
        }
        $rows = Database::sql(
            $sql . ' ORDER BY `id` FOR UPDATE',
            $params,
            index: $index,
        )->rows();
        $ids = [];
        foreach ($rows as $row) {
            $stored = EntityShopGuestCartTransfer::fromRow($row);
            $this->holdStored($stored);
            $ids[] = $stored->id;
        }

        return $ids;
    }

    /**
     * @return int Highest durable transfer ID at the start of the pass, zero for an empty table
     * @throws DatabaseException When the rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function latestStoredTransferId(): int
    {
        return static::entityClass()::get( // fresh-sql: the library does not hold the recipients' transfer sets.
            orderBy: [EntityShopGuestCartTransfer::id => SqlSortDirection::DESC],
            limit: 1,
        )->first()?->id ?? 0;
    }

    /**
     * @param int $limit Maximum number of saved transfer keys to read
     * @param ?int $sessionId Source for a wake, or null for a recovery pass
     * @param int $afterId Exclusive recovery cursor
     * @param int $throughId Inclusive recovery high-water mark
     * @return list<int> Saved keys ordered by epoch for a wake, or ID for recovery
     * @throws DatabaseException When the rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedTransferIds(int $limit, ?int $sessionId, int $afterId, int $throughId): array
    {
        $rows = $sessionId === null
            ? static::entityClass()::get( // fresh-sql: recovery pages the durable table while the mirror holds only cached keys.
                '`id` > ? AND `id` <= ?',
                [$afterId, $throughId],
                [EntityShopGuestCartTransfer::id => SqlSortDirection::ASC],
                $limit,
            )
            : static::entityClass()::get( // fresh-sql: a wake may arrive ahead of DB sync for the session's new assignment.
                [EntityShopGuestCartTransfer::session_id => $sessionId],
                orderBy: [EntityShopGuestCartTransfer::cart_epoch => SqlSortDirection::ASC],
                limit: $limit,
            );

        return array_map(intval(...), $rows->keys());
    }

    /**
     * @param int $transferId Saved transfer key to verify after locking the source
     * @param int $userId Assigned recipient
     * @param int $sessionId Source session
     * @param int $cartEpoch Number of the source cart
     * @return bool Whether the exact assignment is still received and locked
     * @throws HilosException When the locking read fails
     */
    public static function lockReceivedTransfer(int $transferId, int $userId, int $sessionId, int $cartEpoch): bool
    {
        return Database::sql(
            'SELECT `id` FROM ' . EntityShopGuestCartTransfer::getSqlTableName()
            . ' WHERE `id` = ? AND `user_id` = ? AND `session_id` = ? AND `cart_epoch` = ? AND `received` = TRUE FOR UPDATE',
            [$transferId, $userId, $sessionId, $cartEpoch],
            index: EntityShopGuestCartTransfer::getDatabase()->connectionIndex,
        )->firstRow() !== null;
    }

    /**
     * Reads the durable receipt because the cached row can outlive its deletion or predate its receipt.
     *
     * @param int $transferId Saved transfer key
     * @param int $userId Expected recipient
     * @return ?bool Saved received value, or null when the transfer is absent or assigned elsewhere
     * @throws DatabaseException When the row cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedReceipt(int $transferId, int $userId): ?bool
    {
        $transfer = EntityShopGuestCartTransfer::getById($transferId);

        return $transfer?->user_id === $userId ? $transfer->received : null;
    }

    /**
     * @param EntityShopGuestCartTransfer $stored Assignment read from PRIMARY
     * @return ObjectShopGuestCartTransfer Held object with the saved baseline
     * @throws DatabaseException When the object or its database cannot be resolved
     */
    private function holdStored(EntityShopGuestCartTransfer $stored): ObjectShopGuestCartTransfer
    {
        $transferId = $stored->id;
        $held = $this->objects[$transferId] ?? null;
        if ($held !== null) {
            $held->acceptStored($stored);
            return $held;
        }

        $transfer = ObjectShopGuestCartTransfer::fromEntity($stored);
        $this->hydrate($transferId, $transfer);

        return $transfer;
    }
}
