<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser\Data;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserContext;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserData;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;

/**
 * One product's card on its admin page and the stock rows that can change its computed total.
 *
 * The picture's address, edit version and stock total are computed by {@see EcommerceShopBrowserContext}.
 * The names of the product's category and of the warehouse keeping a unique product are not on
 * the card: the page reads them by number from its categories and warehouses lists, which keep
 * renames live.
 */
final class AdminProductBrowserData
{
    public const string DATA = EcommerceShopBrowserData::ADMIN_PRODUCT;
    public const string productId = 'productId';
    public const string FIELD_PICTURE_URL = 'pictureUrl';
    public const string FIELD_EDIT_VERSION = 'editVersion';
    public const string FIELD_STOCK = 'stock';
    public const string STOCK_UNITS = 'units';
    public const string STOCK_WAREHOUSES = 'warehouses';
    public const string STOCK_WAREHOUSE_ID = 'warehouseId';

    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            AdminProductPageSubscribeParams::PRODUCT_ID => [
                BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                BrowserRefKey::KEY => AdminProductPageSubscribeParams::PRODUCT_ID,
            ],
        ],
    ];

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            AdminProductPageSubscribeParams::PRODUCT_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_PRODUCTS,
            EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCTS,
                BrowserDataFieldKey::ROW_KEY => ObjectProduct::id,
                BrowserDataFieldKey::WHERE => [
                    ObjectProduct::id => [
                        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
                        BrowserRefKey::KEY => AdminProductPageSubscribeParams::PRODUCT_ID,
                    ],
                ],
                BrowserDataFieldKey::FIELDS => [
                    ObjectProduct::id => self::productId,
                    ObjectProduct::name, ObjectProduct::slug, ObjectProduct::kind, ObjectProduct::price,
                    ObjectProduct::categoryId, ObjectProduct::description, ObjectProduct::pictureFileId,
                ],
                BrowserDataFieldKey::COMPUTED => [self::FIELD_PICTURE_URL, self::FIELD_EDIT_VERSION, self::FIELD_STOCK],
                BrowserDataFieldKey::NOT_PERSONAL => [self::FIELD_PICTURE_URL, self::FIELD_EDIT_VERSION, self::FIELD_STOCK],
            ],
            [
                BrowserDataFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
                BrowserDataFieldKey::ROW_KEY => ObjectProductWarehouseStock::productId,
                BrowserDataFieldKey::MANY => true,
                BrowserDataFieldKey::FIELDS => [],
            ],
        ],
    ];
}
