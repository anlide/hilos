<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\ProductSlugs as EntityProductSlugs;
use Demo\EcommerceShop\Database\Object\Item\ProductSlug as ObjectProductSlug;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * ProductSlugs - Object collection for the addresses of products.
 *
 * @extends Objects<ObjectProductSlug>
 * @method ObjectProductSlug|null current()
 * @method ObjectProductSlug|null first()
 * @method ObjectProductSlug|null last()
 * @method ObjectProductSlug|null get(int|string $key)
 * @method ObjectProductSlug|null offsetGet(mixed $offset)
 */
final class ProductSlugs extends Objects
{
    public const string OBJECT_CLASS = ObjectProductSlug::class;
    public const string ENTITY_COLLECTION_CLASS = EntityProductSlugs::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::productSlugs;

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
        $addresses = [];
        foreach ($this->readStoredByColumn($column, ...$values) as $entity) {
            $addresses[$entity->slug] = $entity->product_id;
        }

        return $addresses;
    }
}
