<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\ProductSlug as EntityProductSlug;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * ProductSlugs - entity collection for ProductSlug entities.
 *
 * @extends EntityCollection<EntityProductSlug>
 * @implements IteratorAggregate<int|string, EntityProductSlug>
 * @implements ArrayAccess<int|string, EntityProductSlug>
 */
final class ProductSlugs extends EntityCollection
{
    public const string ENTITY_CLASS = EntityProductSlug::class;
}
