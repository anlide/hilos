<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Constants;

use Hilos\Constants\HilosPageConstants;

/**
 * PageConstants - Page constants for the ecommerce-shop demo.
 *
 * Defines page identifiers used for user subscriptions.
 */
final class PageConstants
{
    /** @var string Hilos public Terms history page */
    public const string HILOS_TERMS_HISTORY = HilosPageConstants::HILOS_TERMS_HISTORY;

    /** @var string Hilos public Privacy history page */
    public const string HILOS_PRIVACY_HISTORY = HilosPageConstants::HILOS_PRIVACY_HISTORY;

    public const string HILOS_CHANGE_LOG = HilosPageConstants::HILOS_CHANGE_LOG;

    public const string HILOS_CHANGE_LOG_TABLES = HilosPageConstants::HILOS_CHANGE_LOG_TABLES;

    public const string HILOS_CHANGE_LOG_TABLE = HilosPageConstants::HILOS_CHANGE_LOG_TABLE;

    /** @var string Shop administration: the warehouses table */
    public const string ADMIN_WAREHOUSES = 'admin_warehouses';

    /** @var string Shop administration: the categories table */
    public const string ADMIN_CATEGORIES = 'admin_categories';

    /** @var string Shop administration: the products table */
    public const string ADMIN_PRODUCTS = 'admin_products';

    /** @var string Shop administration: one product's page, addressed by its id */
    public const string ADMIN_PRODUCT = 'admin_product';

    /** @var string Shop administration: one stock product's units by warehouse */
    public const string ADMIN_PRODUCT_STOCK = 'admin_product_stock';

    /** @var string EcommerceShop main page */
    public const string MAIN = 'main';

    /** @var string EcommerceShop product page, addressed by the product's slug */
    public const string PRODUCT = 'product';
}
