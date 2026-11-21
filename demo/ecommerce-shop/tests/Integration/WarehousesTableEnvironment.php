<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;

/** Claims the stock for its library without its start, then stands in for the warehouses page's process, which does not hold the stock. */
abstract class WarehousesTableEnvironment extends StockLibraryEnvironment
{
    /**
     * The library keeps its claim, so the cases write the stock through it; the stock is no longer held,
     * so the totals are read by SQL on the warehouse column, inside the case, and the rollback forgets
     * that read with the rows.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::productWarehouseStocks);
    }

    /** Starts the case from an empty catalog without the stock library's start, so the stock stays not held. */
    protected function setUpInTransaction(): void
    {
        self::emptyCatalog();
    }
}
