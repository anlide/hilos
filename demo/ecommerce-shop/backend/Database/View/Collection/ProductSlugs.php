<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\ProductSlugsActions;
use Demo\EcommerceShop\Database\Object\Collection\ProductSlugs as ObjectProductSlugs;
use Demo\EcommerceShop\Database\View\Item\ProductSlug;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
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

    /**
     * @param string $column History slug or product ID column
     * @param int|string ...$values Keys requested by the caller
     * @return array<int|string, int> Stored exact addresses mapped to product IDs
     * @throws InvalidArgumentException When the column is unknown or the query is invalid
     * @throws LogicException When the entity collection class is not configured
     * @throws DatabaseException When the stored rows cannot be read
     */
    public function storedAddresses(string $column, int|string ...$values): array
    {
        return $this->objectCollection->storedAddresses($column, ...$values);
    }
}
