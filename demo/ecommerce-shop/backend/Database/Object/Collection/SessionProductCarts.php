<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\SessionProductCarts as EntitySessionProductCarts;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Hilos\Database\Object\Objects;

/**
 * SessionProductCarts - Object collection for the cart lines of visitors without an account.
 *
 * @extends Objects<ObjectSessionProductCart>
 * @method ObjectSessionProductCart|null current()
 * @method ObjectSessionProductCart|null first()
 * @method ObjectSessionProductCart|null last()
 * @method ObjectSessionProductCart|null get(int|string $key)
 * @method ObjectSessionProductCart|null offsetGet(mixed $offset)
 */
final class SessionProductCarts extends Objects
{
    public const string OBJECT_CLASS = ObjectSessionProductCart::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySessionProductCarts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::sessionProductCarts;
}
