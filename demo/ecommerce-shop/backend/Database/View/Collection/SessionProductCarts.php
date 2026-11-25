<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\SessionProductCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\SessionProductCarts as ObjectSessionProductCarts;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Demo\EcommerceShop\Database\View\Item\SessionProductCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\HilosException;

/**
 * SessionProductCarts - Db collection of SessionProductCart items: the cart lines of visitors
 * without an account, one per product a browser session put in its cart.
 *
 * @extends DbCollection<SessionProductCart, ObjectSessionProductCarts>
 * @method ObjectSessionProductCarts|null getObjectCollection()
 * @method SessionProductCart|null current()
 * @method SessionProductCart|null first()
 * @method SessionProductCart|null last()
 * @method SessionProductCart offsetGet(mixed $offset)
 * @property-read SessionProductCartsActions $actions Writes for the guest carts library
 */
final class SessionProductCarts extends DbCollection
{
    public const string DB_ITEM_CLASS = SessionProductCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessionProductCarts::class;

    /**
     * @param int $productId Product primary id
     * @return int Number of guest carts containing the product
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countForProduct(int $productId): int
    {
        return $this->objectCollection->countForProduct($productId);
    }

    /**
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the guest cart
     * @return iterable<int, array{int, int}> Saved product ID and quantity by line ID, in line order
     * @throws DatabaseException When the cart rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedCartLines(int $sessionId, int $cartEpoch): iterable
    {
        return $this->objectCollection->storedCartLines($sessionId, $cartEpoch);
    }

    /**
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the guest cart
     * @param int $lineId Primary key of the line being addressed
     * @return ?SessionProductCart The locked cart line, or null when the key is unavailable
     * @throws HilosException When the locking read or reconciliation fails
     * @throws LogicException When the collection cannot provide its item class
     * @throws InvalidArgumentException When the object does not belong to this collection
     */
    public function lockCartLine(int $sessionId, int $cartEpoch, int $lineId): ?SessionProductCart
    {
        $line = $this->objectCollection->lockCartLine($sessionId, $cartEpoch, $lineId);

        return $line === null ? null : $this->createDbItem($line);
    }

    /**
     * @param int $sessionId Cart owner already locked by the caller
     * @param int $cartEpoch Number of the guest cart
     * @param int $productId Catalog product
     * @return ?SessionProductCart The saved line under lock, including one missing from the mirror
     * @throws HilosException When the locking read or reconciliation fails
     * @throws LogicException When the collection cannot provide its item class
     * @throws InvalidArgumentException When the object does not belong to this collection
     */
    public function lockProductLine(int $sessionId, int $cartEpoch, int $productId): ?SessionProductCart
    {
        $line = $this->objectCollection->lockProductLine($sessionId, $cartEpoch, $productId);

        return $line === null ? null : $this->createDbItem($line);
    }

    /**
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the guest cart
     * @param ?int $productId Product to match, null for any line
     * @return bool Whether the current cart has a matching line
     * @throws DatabaseException When the cart membership cannot be read
     */
    public function hasCartLine(int $sessionId, int $cartEpoch, ?int $productId = null): bool
    {
        return $this->objectCollection->hasCartLine($sessionId, $cartEpoch, $productId);
    }

    /**
     * @param int $sessionId Cart owner already locked by the caller
     * @param int $cartEpoch Number of the source cart
     * @return bool Whether the locked pair has any saved product line
     * @throws HilosException When the locking read fails
     */
    public function hasLockedCartLine(int $sessionId, int $cartEpoch): bool
    {
        return $this->objectCollection->hasLockedCartLine($sessionId, $cartEpoch);
    }

    /**
     * @param int $sessionId Session whose source row the caller has locked
     * @param int $cartEpoch Number of the source cart
     * @return bool Whether the exact pair has a pending or received transfer
     * @throws HilosException When the locking read fails
     */
    public function hasLockedTransfer(int $sessionId, int $cartEpoch): bool
    {
        return $this->objectCollection->hasLockedTransfer($sessionId, $cartEpoch);
    }

    /**
     * @param int $sessionId Source session
     * @param int $cartEpoch Source cart number
     * @return array<int, int> Stored quantities keyed by product ID
     * @throws DatabaseException When the cart rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedCartQuantities(int $sessionId, int $cartEpoch): array
    {
        return $this->objectCollection->storedCartQuantities($sessionId, $cartEpoch);
    }

    /**
     * @param int $sessionId Source session
     * @param int $cartEpoch Source cart number
     * @param int $afterProductId Exclusive product cursor, zero at the start
     * @return array<int, int> One saved page of quantities in ascending product ID order
     * @throws DatabaseException When the cart rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedCartQuantityPage(int $sessionId, int $cartEpoch, int $afterProductId): array
    {
        return $this->objectCollection->storedCartQuantityPage($sessionId, $cartEpoch, $afterProductId);
    }

    /**
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the guest cart
     * @return self Selection of the addressed cart's product lines
     * @throws HilosException When the cart collection refuses the read
     */
    public function forCart(int $sessionId, int $cartEpoch): self
    {
        return $this->whereColumnIs(ObjectSessionProductCart::sessionId, $sessionId)
            ->filter(static fn (SessionProductCart $line): bool => $line->cartEpoch === $cartEpoch);
    }

    /**
     * @param int $lineId Key of a possibly sparse cart fact
     * @return ?array{int, int} Persisted session and epoch, or null when the line is gone
     * @throws HilosException When the keyed read fails
     */
    public function storedLineCoordinate(int $lineId): ?array
    {
        return $this->objectCollection->storedLineCoordinate($lineId);
    }

    /**
     * @return array{int, int} Greatest product line ID and voucher binding ID, zero for an empty table
     * @throws NamedDatabaseException When a table or its database cannot be resolved
     * @throws DatabaseException When the query fails
     */
    public function cartRecoveryHorizons(): array
    {
        return $this->objectCollection->cartRecoveryHorizons();
    }

    /**
     * @param int $afterLineId Exclusive lower bound for product line IDs
     * @param int $throughLineId Inclusive upper bound for product line IDs
     * @param int $afterVoucherId Exclusive lower bound for voucher binding IDs
     * @param int $throughVoucherId Inclusive upper bound for voucher binding IDs
     * @param int $limit Maximum number of rows per table
     * @return array{array<int, bool>, array<int, bool>} Ordered cleanup candidate flags by product line ID and voucher binding ID
     * @throws NamedDatabaseException When a table or its database cannot be resolved
     * @throws DatabaseException When a page query or its parameters are refused
     */
    public function cartRowAbsencesAfter(
        int $afterLineId,
        int $throughLineId,
        int $afterVoucherId,
        int $throughVoucherId,
        int $limit,
    ): array {
        return $this->objectCollection->cartRowAbsencesAfter(
            $afterLineId,
            $throughLineId,
            $afterVoucherId,
            $throughVoucherId,
            $limit,
        );
    }
}
