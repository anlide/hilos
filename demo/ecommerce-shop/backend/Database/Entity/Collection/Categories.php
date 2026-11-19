<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * Categories - entity collection for Category entities.
 *
 * @extends EntityCollection<EntityCategory>
 * @implements IteratorAggregate<int|string, EntityCategory>
 * @implements ArrayAccess<int|string, EntityCategory>
 */
final class Categories extends EntityCollection
{
    public const string ENTITY_CLASS = EntityCategory::class;
}
