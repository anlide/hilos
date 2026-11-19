<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\CategorySlugsActions;
use Demo\EcommerceShop\Database\Object\Collection\CategorySlugs as ObjectCategorySlugs;
use Demo\EcommerceShop\Database\View\Item\CategorySlug;
use Hilos\Database\View\Collection\DbCollection;

/**
 * CategorySlugs - Db collection of CategorySlug items: every address a category has had.
 *
 * @extends DbCollection<CategorySlug, ObjectCategorySlugs>
 * @method ObjectCategorySlugs|null getObjectCollection()
 * @method CategorySlug|null current()
 * @method CategorySlug|null first()
 * @method CategorySlug|null last()
 * @method CategorySlug offsetGet(mixed $offset)
 * @property-read CategorySlugsActions $actions Collection actions (record, retire), for the actions of a category only
 */
final class CategorySlugs extends DbCollection
{
    public const string DB_ITEM_CLASS = CategorySlug::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCategorySlugs::class;
}
