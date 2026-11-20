<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\SessionProductCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\SessionProductCarts as ObjectSessionProductCarts;
use Demo\EcommerceShop\Database\View\Item\SessionProductCart;
use Hilos\Database\View\Collection\DbCollection;

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
 * @property-read SessionProductCartsActions $actions Collection actions (add, step, setQuantity, remove, deleteForSessions),
 *     for the guest carts library only
 */
final class SessionProductCarts extends DbCollection
{
    public const string DB_ITEM_CLASS = SessionProductCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessionProductCarts::class;
}
