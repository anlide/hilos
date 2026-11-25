<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\ProductsActions;
use Demo\EcommerceShop\Database\Object\Collection\Products as ObjectProducts;
use Demo\EcommerceShop\Database\View\Item\Product;
use Generator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
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
     * @param string $column Product ID or slug column
     * @param int|string ...$values Exact keys requested by the caller
     * @return Generator<int, Product> Product snapshots from the stored rows, keyed by ID
     * @throws InvalidArgumentException When the column is unknown or the query is invalid
     * @throws LogicException When the entity collection class is not configured
     * @throws DatabaseException When the stored rows cannot be read
     */
    public function storedProducts(string $column, int|string ...$values): Generator
    {
        foreach ($this->objectCollection->storedProducts($column, ...$values) as $id => $product) {
            yield $id => new Product($product);
        }
    }

    /**
     * Reads the product's slug only if its stored row still belongs to the category.
     *
     * @param int $productId Product primary key
     * @param int $categoryId Category the product must belong to
     * @return ?string Stored slug, or null if the product is missing or belongs to another category
     * @throws ObjectCollectionNullException When the collection is detached
     * @throws DatabaseException When the product cannot be read
     * @throws InvalidArgumentException When the Entity query is invalid
     */
    public function storedSlugInCategory(int $productId, int $categoryId): ?string
    {
        return $this->objectCollection->storedSlugInCategory($productId, $categoryId);
    }

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
