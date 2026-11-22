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
 * addresses are its catalog, the stock counts units in each warehouse, and the vouchers
 * are shop discount codes. These collections hold no personal data. The guest carts belong to
 * browser sessions without an account and have no column pointing at a person, so the erasure
 * leaves them alone too. The carts of the people with an account are the one collection of the
 * shop that holds a person's rows: the person's agent holds them and erases them in its own part
 * of the erasure.
 */
final class EcommerceShopAccountDataOwnerDirectory extends AccountDataOwnerDirectory
{
    /**
     * @return array<string, AccountErasureExemption> Non-personal warehouses, catalog, stock and vouchers,
     *     and guest carts held apart from any account
     */
    protected static function exempt(): array
    {
        return [
            EcommerceShopDbContext::warehouses => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::categories => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::categorySlugs => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::products => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::productSlugs => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::productWarehouseStocks => AccountErasureExemption::NotPersonal,
            EcommerceShopDbContext::sessionProductCarts => AccountErasureExemption::OutsideAccount,
            EcommerceShopDbContext::vouchers => AccountErasureExemption::NotPersonal,
        ];
    }

    /** @return list<string> The carts of the people with an account, which the person's agent holds and erases */
    protected static function personal(): array
    {
        return [EcommerceShopDbContext::userProductCarts];
    }
}
