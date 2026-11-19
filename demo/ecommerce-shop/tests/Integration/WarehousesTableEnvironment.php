<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Hilos;

/** Starts the stock library as its worker does, then forgets what that start loaded, so the stock is read from the database again. */
abstract class WarehousesTableEnvironment extends StockLibraryEnvironment
{
    /**
     * The library keeps its claim, so the cases write the stock through it; the next read of the stock
     * reads the table again, inside the case, and the rollback forgets that read with the rows.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetLoadedDataForTest();
    }
}
