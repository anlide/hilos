<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\DTO;

/**
 * What the warehouses keep of one product: its units in all of them and, when one warehouse
 * alone keeps it, which one.
 *
 * Summed from the product's stock rows; a row exists only while it holds a unit, so a product no
 * warehouse keeps is zero units and no warehouse.
 */
final readonly class ProductStockTotals
{
    /**
     * @param int $unitCount Units of the product's stock rows summed, 0 when it has none
     * @param ?int $warehouseId Warehouse of the product's only stock row; null when it has no row or several
     */
    public function __construct(
        public int $unitCount,
        public ?int $warehouseId,
    ) {
    }
}
