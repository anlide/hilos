<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\UserProductCarts as EntityUserProductCarts;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Hilos\Database\Object\Objects;

/**
 * UserProductCarts - Object collection for the cart lines of people with an account.
 *
 * @extends Objects<ObjectUserProductCart>
 * @method ObjectUserProductCart|null current()
 * @method ObjectUserProductCart|null first()
 * @method ObjectUserProductCart|null last()
 * @method ObjectUserProductCart|null get(int|string $key)
 * @method ObjectUserProductCart|null offsetGet(mixed $offset)
 */
final class UserProductCarts extends Objects
{
    public const string OBJECT_CLASS = ObjectUserProductCart::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserProductCarts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::userProductCarts;
}
