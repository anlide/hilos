<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\DTO;

/**
 * What one warehouse keeps: how many different products and how many units of them in all.
 *
 * Counted from the warehouse's stock rows; a row exists only while it holds a unit, so an
 * empty warehouse is zero products and zero units.
 */
final readonly class WarehouseStockTotals
{
    /**
     * @param int $productCount Stock rows of the warehouse, one per product it keeps
     * @param int $unitCount Units of those rows summed
     */
    public function __construct(
        public int $productCount,
        public int $unitCount,
    ) {
    }
}
