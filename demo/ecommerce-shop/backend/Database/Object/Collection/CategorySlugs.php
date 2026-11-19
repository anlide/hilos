<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\CategorySlugs as EntityCategorySlugs;
use Demo\EcommerceShop\Database\Object\Item\CategorySlug as ObjectCategorySlug;
use Hilos\Database\Object\Objects;

/**
 * CategorySlugs - Object collection for the addresses of categories.
 *
 * @extends Objects<ObjectCategorySlug>
 * @method ObjectCategorySlug|null current()
 * @method ObjectCategorySlug|null first()
 * @method ObjectCategorySlug|null last()
 * @method ObjectCategorySlug|null get(int|string $key)
 * @method ObjectCategorySlug|null offsetGet(mixed $offset)
 */
final class CategorySlugs extends Objects
{
    public const string OBJECT_CLASS = ObjectCategorySlug::class;
    public const string ENTITY_COLLECTION_CLASS = EntityCategorySlugs::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::categorySlugs;
}
