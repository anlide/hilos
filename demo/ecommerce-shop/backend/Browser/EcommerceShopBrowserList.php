<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser;

/**
 * Browser list keys of the shop's pages.
 */
final class EcommerceShopBrowserList
{
    /** @var string The categories the home page shows as tiles */
    public const string MAIN_CATEGORIES = 'mainCategories';

    /** @var string The products the home page counts into its category tiles */
    public const string MAIN_PRODUCTS = 'mainProducts';

    /** @var string The categories the products page offers in its Category filter */
    public const string ADMIN_PRODUCT_CATEGORIES = 'adminProductCategories';
}
