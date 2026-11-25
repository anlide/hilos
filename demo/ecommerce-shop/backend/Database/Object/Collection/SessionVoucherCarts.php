<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\SessionVoucherCarts as EntitySessionVoucherCarts;
use Demo\EcommerceShop\Database\Entity\Item\SessionVoucherCart as EntitySessionVoucherCart;
use Demo\EcommerceShop\Database\Object\Item\SessionVoucherCart as ObjectSessionVoucherCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;

/**
 * Voucher bindings keyed by physical binding ID.
 *
 * @extends Objects<ObjectSessionVoucherCart>
 * @method ObjectSessionVoucherCart|null first()
 * @method ObjectSessionVoucherCart|null last()
 * @method ObjectSessionVoucherCart|null get(int|string $key)
 * @method ObjectSessionVoucherCart|null offsetGet(mixed $offset)
 */
final class SessionVoucherCarts extends Objects
{
    public const string OBJECT_CLASS = ObjectSessionVoucherCart::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySessionVoucherCarts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::sessionVoucherCarts;

    /**
     * Reads a cart's saved voucher without changing the held rows.
     *
     * @param int $sessionId Session that holds the voucher slot
     * @param int $cartEpoch Number of the guest cart
     * @return ?int Selected voucher ID, or null for an empty slot
     * @throws DatabaseException When the saved row cannot be read
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function storedVoucherId(int $sessionId, int $cartEpoch): ?int
    {
        return EntitySessionVoucherCart::get( // fresh-sql: the published cart must reflect the saved pair.
            [EntitySessionVoucherCart::session_id => $sessionId, EntitySessionVoucherCart::cart_epoch => $cartEpoch],
        )->first()?->voucher_id;
    }

    /**
     * @param int $bindingId Physical key of a candidate or sparse fact
     * @return ?array{int, int} Saved session and cart epoch, or null when the binding is gone
     * @throws DatabaseException When the saved row cannot be read
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function storedSlotCoordinate(int $bindingId): ?array
    {
        $stored = EntitySessionVoucherCart::getById($bindingId);

        return $stored === null ? null : [$stored->session_id, $stored->cart_epoch];
    }

    /**
     * @param list<int> $sessionIds Swept sessions
     * @return array<int, array<int, int>> Physical binding IDs by session and epoch
     * @throws HilosException When the stored rows cannot be selected
     */
    public function storedSlotIdsByPair(array $sessionIds): array
    {
        $ids = [];
        foreach ($this->readStoredByColumn(EntitySessionVoucherCart::session_id, ...$sessionIds) as $binding) {
            $ids[$binding->session_id][$binding->cart_epoch] = $binding->id;
        }

        return $ids;
    }

    /**
     * Checks the saved slot with a current read after the caller locked the session.
     *
     * @param int $sessionId Session that holds the voucher slot
     * @param int $cartEpoch Number of the guest cart
     * @return bool Whether that exact pair has a saved voucher
     * @throws NamedDatabaseException When the binding database or table cannot be resolved
     * @throws DatabaseException When the locking read fails
     */
    public function hasLockedSlot(int $sessionId, int $cartEpoch): bool
    {
        return $this->lockStoredSlot($sessionId, $cartEpoch) !== null;
    }

    /**
     * Locks the saved slot after the source session and reconciles its physical key for an ORM write.
     *
     * @param int $sessionId Session that holds the voucher slot
     * @param int $cartEpoch Number of the guest cart
     * @param ?int $candidateId Physical key when rechecking a bounded sweep candidate
     * @return ?ObjectSessionVoucherCart Persisted binding, or null for an empty slot
     * @throws NamedDatabaseException When the binding database or table cannot be resolved
     * @throws DatabaseException When the locking read or reconciliation fails
     */
    public function loadStoredSlot(int $sessionId, int $cartEpoch, ?int $candidateId = null): ?ObjectSessionVoucherCart
    {
        $stored = $this->lockStoredSlot($sessionId, $cartEpoch);
        if ($stored === null || ($candidateId !== null && $stored->id !== $candidateId)) {
            return null;
        }

        // The physical key is authoritative. A deleted key can await its own DB_SYNC.
        $held = $this->objects[$stored->id] ?? null;
        if ($held !== null) {
            $held->acceptStored($stored);

            return $held;
        }

        $binding = ObjectSessionVoucherCart::fromEntity($stored);
        $this->hydrate($stored->id, $binding);

        return $binding;
    }

    /**
     * @param int $sessionId Session that holds the voucher slot
     * @param int $cartEpoch Number of the guest cart
     * @return ?EntitySessionVoucherCart Locked slot, or null when the pair has no voucher
     * @throws NamedDatabaseException When the binding database or table cannot be resolved
     * @throws DatabaseException When the locking read fails
     */
    private function lockStoredSlot(int $sessionId, int $cartEpoch): ?EntitySessionVoucherCart
    {
        $index = EntitySessionVoucherCart::getDatabase()->connectionIndex;
        if (Database::runtimeTransactionLevel($index) === null) {
            throw new DatabaseException('The guest voucher slot lock requires a PRIMARY transaction.');
        }

        $row = Database::sql(
            'SELECT * FROM ' . EntitySessionVoucherCart::getSqlTableName()
            . ' WHERE `session_id` = ? AND `cart_epoch` = ? FOR UPDATE',
            [$sessionId, $cartEpoch],
            index: $index,
        )->firstRow();

        return $row === null ? null : EntitySessionVoucherCart::fromRow($row);
    }
}
