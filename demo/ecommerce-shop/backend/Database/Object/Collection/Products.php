<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\Products as EntityProducts;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Generator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * Products - Object collection for the products of the catalog.
 *
 * @extends Objects<ObjectProduct>
 * @method ObjectProduct|null current()
 * @method ObjectProduct|null first()
 * @method ObjectProduct|null last()
 * @method ObjectProduct|null get(int|string $key)
 * @method ObjectProduct|null offsetGet(mixed $offset)
 */
final class Products extends Objects
{
    public const string OBJECT_CLASS = ObjectProduct::class;
    public const string ENTITY_COLLECTION_CLASS = EntityProducts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::products;

    /**
     * @param string $column Product ID or slug column
     * @param int|string ...$values Exact keys requested by the caller
     * @return Generator<int, ObjectProduct> Detached product rows keyed by ID
     * @throws InvalidArgumentException When the column is unknown or the query is invalid
     * @throws LogicException When the entity collection class is not configured
     * @throws DatabaseException When the stored rows cannot be read
     */
    public function storedProducts(string $column, int|string ...$values): Generator
    {
        foreach ($this->readStoredByColumn($column, ...$values) as $id => $entity) {
            yield $id => ObjectProduct::fromEntity($entity);
        }
    }

    /**
     * Reads the current category and slug together without refreshing the local product mirror.
     *
     * @param int $productId Product primary key
     * @param int $categoryId Category the product must belong to
     * @return ?string Stored slug, or null if the product is missing or belongs to another category
     * @throws DatabaseException When the product cannot be read
     * @throws InvalidArgumentException When the Entity query is invalid
     */
    public function storedSlugInCategory(int $productId, int $categoryId): ?string
    {
        $product = EntityProduct::getById($productId); // fresh-sql: a category Add can arrive before the product move is synced.

        return $product !== null && $product->category_id === $categoryId ? $product->slug : null;
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
        return $this->countByColumn(EntityProduct::category_id, $categoryId);
    }
}
