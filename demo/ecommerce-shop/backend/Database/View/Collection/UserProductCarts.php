<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\UserProductCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\UserProductCarts as ObjectUserProductCarts;
use Demo\EcommerceShop\Database\View\Item\UserProductCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\HilosException;

/**
 * UserProductCarts - Db collection of UserProductCart items: the cart lines of people with an
 * account, one per product a person put in their cart.
 *
 * @extends DbCollection<UserProductCart, ObjectUserProductCarts>
 * @method ObjectUserProductCarts|null getObjectCollection()
 * @method UserProductCart|null current()
 * @method UserProductCart|null first()
 * @method UserProductCart|null last()
 * @method UserProductCart offsetGet(mixed $offset)
 * @property-read UserProductCartsActions $actions Collection actions (add, step, setQuantity, remove, deleteForUser),
 *     for the person's agent only
 */
final class UserProductCarts extends DbCollection
{
    public const string DB_ITEM_CLASS = UserProductCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserProductCarts::class;

    /**
     * @param int $productId Product primary id
     * @return int Number of account carts containing the product
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countForProduct(int $productId): int
    {
        return $this->objectCollection->countForProduct($productId);
    }

    /**
     * @param int $userId Cart owner
     * @return iterable<int, array{int, int}> Saved product ID and quantity by line ID, in line order
     * @throws DatabaseException When the cart rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedCartLines(int $userId): iterable
    {
        return $this->objectCollection->storedCartLines($userId);
    }

    /**
     * @param int $userId Cart owner
     * @param int $lineId Primary key of the line being addressed
     * @return ?UserProductCart The locked cart line, or null when the key is unavailable
     * @throws HilosException When the locking read or reconciliation fails
     * @throws LogicException When the collection cannot provide its item class
     * @throws InvalidArgumentException When the object does not belong to this collection
     */
    public function lockCartLine(int $userId, int $lineId): ?UserProductCart
    {
        $line = $this->objectCollection->lockCartLine($userId, $lineId);

        return $line === null ? null : $this->createDbItem($line);
    }

    /**
     * @param int $userId Cart owner
     * @param ?int $productId Product to match, null for any line
     * @return bool Whether the current cart has a matching line
     * @throws DatabaseException When the cart membership cannot be read
     */
    public function hasCartLine(int $userId, ?int $productId = null): bool
    {
        return $this->objectCollection->hasCartLine($userId, $productId);
    }
}
