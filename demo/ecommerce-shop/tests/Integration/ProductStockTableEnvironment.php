<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;

/** Starts the stock library as its worker does, then stands in for the connection's process, which does not hold the stock. */
abstract class ProductStockTableEnvironment extends StockLibraryEnvironment
{
    /**
     * The library keeps its claim, so the cases write the stock through it; the stock is no longer held,
     * so the window reads the product's stock by SQL on the product column, inside the case, and the
     * rollback forgets that read with the rows.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::productWarehouseStocks);
    }
}
