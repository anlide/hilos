<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\Products as EntityProducts;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
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
}
