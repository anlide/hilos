<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\ProductsActions;
use Demo\EcommerceShop\Database\Object\Collection\Products as ObjectProducts;
use Demo\EcommerceShop\Database\View\Item\Product;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Products - Db collection of Product items.
 *
 * @extends DbCollection<Product, ObjectProducts>
 * @method ObjectProducts|null getObjectCollection()
 * @method Product|null current()
 * @method Product|null first()
 * @method Product|null last()
 * @method Product offsetGet(mixed $offset)
 * @property-read ProductsActions $actions Collection actions (create); items use ProductActions
 */
final class Products extends DbCollection
{
    public const string DB_ITEM_CLASS = Product::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectProducts::class;

    /**
     * @param int $categoryId Category primary id
     * @return int Number of its products of either kind, a unique piece not yet in a warehouse included
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countInCategory(int $categoryId): int
    {
        return $this->objectCollection->countInCategory($categoryId);
    }
}
