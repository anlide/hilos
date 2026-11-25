<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\UserProductCarts as EntityUserProductCarts;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart as EntityUserProductCart;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
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

    /**
     * @param int $productId Product primary id
     * @return int Number of account carts containing the product
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countForProduct(int $productId): int
    {
        return $this->countByColumn(EntityUserProductCart::product_id, $productId);
    }

    /**
     * Checks the cart's owner/product index without walking the held rows or loading new objects.
     *
     * @param int $userId Cart owner
     * @param ?int $productId Product to match, null for any line
     * @return bool Whether the current cart has a matching line
     * @throws DatabaseException When the cart membership cannot be read
     */
    public function hasCartLine(int $userId, ?int $productId = null): bool
    {
        $filters = [EntityUserProductCart::user_id => $userId];
        if ($productId !== null) {
            $filters[EntityUserProductCart::product_id] = $productId;
        }

        return EntityUserProductCart::countUpTo(0, $filters) > 0;
    }
}
