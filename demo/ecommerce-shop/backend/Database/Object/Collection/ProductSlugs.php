<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\ProductSlugs as EntityProductSlugs;
use Demo\EcommerceShop\Database\Object\Item\ProductSlug as ObjectProductSlug;
use Hilos\Database\Object\Objects;

/**
 * ProductSlugs - Object collection for the addresses of products.
 *
 * @extends Objects<ObjectProductSlug>
 * @method ObjectProductSlug|null current()
 * @method ObjectProductSlug|null first()
 * @method ObjectProductSlug|null last()
 * @method ObjectProductSlug|null get(int|string $key)
 * @method ObjectProductSlug|null offsetGet(mixed $offset)
 */
final class ProductSlugs extends Objects
{
    public const string OBJECT_CLASS = ObjectProductSlug::class;
    public const string ENTITY_COLLECTION_CLASS = EntityProductSlugs::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::productSlugs;
}
