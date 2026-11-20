<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser\List;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;

/**
 * Category filter options for the products page: every shop category with its name
 * and position in the catalog. A list keeps creation, renaming and deletion live.
 * The snapshot is queried directly and needs no categories holder in the page's process.
 */
final class AdminProductCategoriesBrowserList
{
    public const string LIST = EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES;

    public const array BROWSER = [
        BrowserListConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_CATEGORIES,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => EcommerceShopBrowserSource::DB_CATEGORIES,
                BrowserListFieldKey::ITEM_KEY => ObjectCategory::id,
                BrowserListFieldKey::FIELDS => [
                    ObjectCategory::id,
                    ObjectCategory::name,
                    ObjectCategory::position,
                ],
            ],
        ],
    ];
}
