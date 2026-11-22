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

    /** @var string Subscription page main signal name */
    public const string SUBSCRIPTION_PAGE_MAIN = 'subscription_page_main';

    /** @var string Subscription page admin warehouses signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_WAREHOUSES = 'subscription_page_admin_warehouses';

    /** @var string Warehouse create action name */
    public const string WAREHOUSE_CREATE = 'warehouse_create';

    /** @var string Subscription page admin categories signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_CATEGORIES = 'subscription_page_admin_categories';

    /** @var string Category move up action name */
    public const string CATEGORY_MOVE_UP = 'category_move_up';

    /** @var string Subscription page admin products signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_PRODUCTS = 'subscription_page_admin_products';

    /** @var string Subscription page admin product signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_PRODUCT = 'subscription_page_admin_product';

    /** @var string Server → client, WS_GROUP: the whole cart of one guest session or one person, sent by its owner */
    public const string CART_STATE = 'cart_state';

    /** @var string Shop connections agent → guest carts library: tell every tab of a session its cart */
    public const string GUEST_CART_SEND = 'guest_cart_send';

    /** @var string Shop connections agent → the person's agent: tell every tab and device of the person the cart */
    public const string ACCOUNT_CART_SEND = 'account_cart_send';
}
