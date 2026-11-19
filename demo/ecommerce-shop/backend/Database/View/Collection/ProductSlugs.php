<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\ProductSlugsActions;
use Demo\EcommerceShop\Database\Object\Collection\ProductSlugs as ObjectProductSlugs;
use Demo\EcommerceShop\Database\View\Item\ProductSlug;
use Hilos\Database\View\Collection\DbCollection;

/**
 * ProductSlugs - Db collection of ProductSlug items: every address a product has had.
 *
 * @extends DbCollection<ProductSlug, ObjectProductSlugs>
 * @method ObjectProductSlugs|null getObjectCollection()
 * @method ProductSlug|null current()
 * @method ProductSlug|null first()
 * @method ProductSlug|null last()
 * @method ProductSlug offsetGet(mixed $offset)
 * @property-read ProductSlugsActions $actions Collection actions (record), for the actions of a product only
 */
final class ProductSlugs extends DbCollection
{
    public const string DB_ITEM_CLASS = ProductSlug::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectProductSlugs::class;
}
