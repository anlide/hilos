<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Constants;

/**
 * EcommerceShopSignalConstants - ecommerce-shop signal name constants.
 *
 * Defines signal name constants used in the ecommerce-shop demo.
 */
final class EcommerceShopSignalConstants
{
    /** @var string Handshake response signal name */
    public const string HANDSHAKE_RESPONSE = 'handshake_response';

    /** @var string Subscription page admin warehouses signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_WAREHOUSES = 'subscription_page_admin_warehouses';
}
