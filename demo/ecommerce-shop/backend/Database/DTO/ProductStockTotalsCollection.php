<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\DTO;

use ArrayIterator;
use Countable;
use Hilos\Core\Exception\InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * The stock totals of several products, one for every product they were read for, by product id.
 *
 * @implements IteratorAggregate<int, ProductStockTotals>
 */
final readonly class ProductStockTotalsCollection implements Countable, IteratorAggregate
{
    /**
     * @param array<int, ProductStockTotals> $totals Totals by product id
     */
    public function __construct(private array $totals)
    {
    }

    /**
     * Reads the totals of one product they were read for.
     *
     * @param int $productId Id of the product
     * @return ProductStockTotals Its totals
     * @throws InvalidArgumentException When the totals were not read for this product
     */
    public function of(int $productId): ProductStockTotals
    {
        return $this->totals[$productId]
            ?? throw new InvalidArgumentException("Stock totals were not read for product {$productId}");
    }

    /**
     * @return int How many products the totals were read for
     */
    public function count(): int
    {
        return count($this->totals);
    }

    /**
     * @return Traversable<int, ProductStockTotals> Totals by product id, in the order the ids were asked
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->totals);
    }
}
