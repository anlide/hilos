<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;

/** Stands in for the storefront's category page process, which holds neither products nor stock. */
abstract class CategoryProductsTableEnvironment extends ProductsTableEnvironment
{
    /** Drops the holdings before the rollback scope so reads within the case go to the database. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::products, EcommerceShopDbContext::productWarehouseStocks);
    }
}
