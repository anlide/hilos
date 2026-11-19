<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\CategorySlug as EntityCategorySlug;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * CategorySlugs - entity collection for CategorySlug entities.
 *
 * @extends EntityCollection<EntityCategorySlug>
 * @implements IteratorAggregate<int|string, EntityCategorySlug>
 * @implements ArrayAccess<int|string, EntityCategorySlug>
 */
final class CategorySlugs extends EntityCollection
{
    public const string ENTITY_CLASS = EntityCategorySlug::class;
}
