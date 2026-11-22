<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser\List;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;

/**
 * Warehouse names for one product's page: every shop warehouse with its name, read by the number
 * the product's stock names. A list keeps creation, renaming and deletion live.
 * The snapshot is queried directly and needs no warehouses holder in the page's process.
 */
final class AdminProductWarehousesBrowserList
{
    public const string LIST = EcommerceShopBrowserList::ADMIN_PRODUCT_WAREHOUSES;

    public const array BROWSER = [
        BrowserListConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_WAREHOUSES,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => EcommerceShopBrowserSource::DB_WAREHOUSES,
                BrowserListFieldKey::ITEM_KEY => ObjectWarehouse::id,
                BrowserListFieldKey::FIELDS => [
                    ObjectWarehouse::id,
                    ObjectWarehouse::name,
                ],
            ],
        ],
    ];
}
