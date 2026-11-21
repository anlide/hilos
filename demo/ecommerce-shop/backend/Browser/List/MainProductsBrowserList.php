<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser\List;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;

/**
 * The products of the home page: every product with its category and whether it is on the shop
 * front. The browser counts a category's products on the front into its tile; a list cannot take
 * the step from a stock row to a product to its category, so the count is not a field here.
 *
 * The product row is rebuilt only when the product moves to another category: a renamed product
 * changes no tile. The stock row is here so that a unique piece laid into a warehouse rebuilds its
 * item and comes onto the front; it is rebuilt only when a stock row appears or leaves its product,
 * because a stock item's number of units does not change whether it is on the front.
 */
final class MainProductsBrowserList
{
    public const string LIST = EcommerceShopBrowserList::MAIN_PRODUCTS;

    /** @var string Whether the product is on the shop front */
    public const string ON_SHOP_FRONT = 'onShopFront';

    public const array BROWSER = [
        BrowserListConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_PRODUCTS,
            EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCTS,
                BrowserListFieldKey::ITEM_KEY => ObjectProduct::id,
                BrowserListFieldKey::FIELDS => [
                    ObjectProduct::id,
                    ObjectProduct::categoryId,
                ],
                BrowserListFieldKey::COMPUTED => [
                    self::ON_SHOP_FRONT,
                ],
                BrowserListFieldKey::NOT_PERSONAL => [
                    self::ON_SHOP_FRONT,
                ],
                BrowserListFieldKey::TRIGGERS => [
                    ObjectProduct::categoryId,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
                BrowserListFieldKey::ITEM_KEY => ObjectProductWarehouseStock::productId,
                BrowserListFieldKey::FIELDS => [
                    ObjectProductWarehouseStock::productId,
                ],
                BrowserListFieldKey::TRIGGERS => [
                    ObjectProductWarehouseStock::productId,
                ],
            ],
        ],
    ];
}
