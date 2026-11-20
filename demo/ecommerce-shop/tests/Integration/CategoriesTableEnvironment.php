<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;

/** Writes categories as their library does and stands in for the categories page's process, which does not hold the products. */
abstract class CategoriesTableEnvironment extends CategoriesLibraryEnvironment
{
    /**
     * A products library started earlier in this process leaves the products promised whole; the holding
     * is forgotten, so a count of products goes by SQL on the category column, inside the case, and the
     * rollback forgets that read with the rows.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::products);
    }
}
