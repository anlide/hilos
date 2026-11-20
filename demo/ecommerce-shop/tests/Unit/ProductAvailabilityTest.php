<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Constants\ProductKind;
use PHPUnit\Framework\TestCase;

/** How much of a product there is to sell, told by its kind and its units, at the edges of every class. */
final class ProductAvailabilityTest extends TestCase
{
    /** A stock item is out of stock at none, low from one to five units and in stock from six. */
    public function testAStockItemIsOutOfStockLowOrInStockByItsUnits(): void
    {
        self::assertSame(ProductAvailability::OUT_OF_STOCK, ProductAvailability::of(ProductKind::STOCK, 0));
        self::assertSame(ProductAvailability::LOW, ProductAvailability::of(ProductKind::STOCK, 1));
        self::assertSame(ProductAvailability::LOW, ProductAvailability::of(ProductKind::STOCK, 5));
        self::assertSame(ProductAvailability::IN_STOCK, ProductAvailability::of(ProductKind::STOCK, 6));
    }

    /** A unique piece is not placed until a warehouse keeps it, and free once one does. */
    public function testAUniquePieceIsFreeOnlyOnceAWarehouseKeepsIt(): void
    {
        self::assertSame(ProductAvailability::NOT_PLACED, ProductAvailability::of(ProductKind::UNIQUE, 0));
        self::assertSame(ProductAvailability::FREE, ProductAvailability::of(ProductKind::UNIQUE, 1));
    }

    /** The most units a stock item has while still low is five, one threshold for the admin and the storefront. */
    public function testTheLowThresholdIsFiveUnits(): void
    {
        self::assertSame(5, ProductAvailability::LOW_UNITS_MAX);
    }
}
