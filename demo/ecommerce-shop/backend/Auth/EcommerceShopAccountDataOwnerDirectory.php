<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Auth;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureExemption;

/**
 * EcommerceShopAccountDataOwnerDirectory - whose the shop's own collections are.
 *
 * The warehouses are the shop's premises, the categories and the products with their
 * addresses are its catalog, and the stock is how many units of each product each warehouse
 * keeps: nobody's personal data, so they stay out of the erasure. No collection of the shop
 * holds a person's rows yet, so there is no owner to name.
 */
final class EcommerceShopAccountDataOwnerDirectory extends AccountDataOwnerDirectory
{
    /** @return array<string, AccountErasureExemption> The warehouses, the catalog and the stock, which carry nothing personal */
    protected static function exempt(): array
    {
        return [
            EcommerceShopDbContext::warehouses => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::categories => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::categorySlugs => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::products => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::productSlugs => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::productWarehouseStocks => AccountErasureExemption::NotPersonal,
        ];
    }
}
