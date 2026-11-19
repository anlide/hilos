<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\Categories as EntityCategories;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Hilos\Database\Object\Objects;

/**
 * Categories - Object collection for categories.
 *
 * @extends Objects<ObjectCategory>
 * @method ObjectCategory|null current()
 * @method ObjectCategory|null first()
 * @method ObjectCategory|null last()
 * @method ObjectCategory|null get(int|string $key)
 * @method ObjectCategory|null offsetGet(mixed $offset)
 */
final class Categories extends Objects
{
    public const string OBJECT_CLASS = ObjectCategory::class;
    public const string ENTITY_COLLECTION_CLASS = EntityCategories::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::categories;
}
