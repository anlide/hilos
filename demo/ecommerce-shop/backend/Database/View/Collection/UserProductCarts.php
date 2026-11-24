<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\UserProductCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\UserProductCarts as ObjectUserProductCarts;
use Demo\EcommerceShop\Database\View\Item\UserProductCart;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;

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
