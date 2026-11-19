<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\Products as EntityProducts;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * Products - Object collection for the products of the catalog.
 *
 * @extends Objects<ObjectProduct>
 * @method ObjectProduct|null current()
 * @method ObjectProduct|null first()
 * @method ObjectProduct|null last()
 * @method ObjectProduct|null get(int|string $key)
 * @method ObjectProduct|null offsetGet(mixed $offset)
 */
final class Products extends Objects
{
    public const string OBJECT_CLASS = ObjectProduct::class;
    public const string ENTITY_COLLECTION_CLASS = EntityProducts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::products;

    /**
     * @param int $categoryId Category primary id
     * @return int Number of its products of either kind, a unique piece not yet in a warehouse included
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countInCategory(int $categoryId): int
    {
        return $this->countByColumn(EntityProduct::category_id, $categoryId);
    }
}
