<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class EcommerceShopBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => EcommerceShopDbContext::users,
    ];

    public const array DB_WAREHOUSES = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => EcommerceShopDbContext::warehouses,
    ];

    public const array DB_PRODUCT_WAREHOUSE_STOCKS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => EcommerceShopDbContext::productWarehouseStocks,
    ];

    public const array DB_CATEGORIES = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => EcommerceShopDbContext::categories,
    ];

    public const array DB_PRODUCTS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => EcommerceShopDbContext::products,
    ];

    public const array DB_VOUCHERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => EcommerceShopDbContext::vouchers,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => EcommerceShopRtContext::connections,
    ];
}
