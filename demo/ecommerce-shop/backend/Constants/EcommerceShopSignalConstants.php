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

    /** @var string Subscription page category signal name */
    public const string SUBSCRIPTION_PAGE_CATEGORY = 'subscription_page_category';

    /** @var string Subscription page admin warehouses signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_WAREHOUSES = 'subscription_page_admin_warehouses';

    /** @var string Warehouse create action name */
    public const string WAREHOUSE_CREATE = 'warehouse_create';

    /** @var string Warehouse update action name */
    public const string WAREHOUSE_UPDATE = 'warehouse_update';

    /** @var string Warehouse delete action name */
    public const string WAREHOUSE_DELETE = 'warehouse_delete';

    /** @var string Subscription page admin categories signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_CATEGORIES = 'subscription_page_admin_categories';

    /** @var string Category move up action name */
    public const string CATEGORY_MOVE_UP = 'category_move_up';

    /** @var string Category create action name */
    public const string CATEGORY_CREATE = 'category_create';

    /** @var string Category update action name */
    public const string CATEGORY_UPDATE = 'category_update';

    /** @var string Category delete action name */
    public const string CATEGORY_DELETE = 'category_delete';

    /** @var string Category picture publish action name: a new or replacement picture is published once it is uploaded */
    public const string CATEGORY_PICTURE_PUBLISH = 'category_picture_publish';

    /** @var string Files door → categories library: the answer to a category picture publication */
    public const string CATEGORY_PICTURE_PUBLISHED = 'category_picture_published';

    /** @var string Subscription page admin products signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_PRODUCTS = 'subscription_page_admin_products';

    /** @var string Subscription page admin vouchers signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_VOUCHERS = 'subscription_page_admin_vouchers';

    /** @var string Voucher create action name */
    public const string VOUCHER_CREATE = 'voucher_create';

    /** @var string Voucher update action name */
    public const string VOUCHER_UPDATE = 'voucher_update';

    /** @var string Voucher active toggle action name */
    public const string VOUCHER_TOGGLE_ACTIVE = 'voucher_toggle_active';

    /** @var string Product create action name */
    public const string PRODUCT_CREATE = 'product_create';

    /** @var string Product update action name */
    public const string PRODUCT_UPDATE = 'product_update';

    /** @var string Overview action to place a unique piece in a warehouse */
    public const string PRODUCT_WAREHOUSE_PLACE = 'product_warehouse_place';

    /** @var string Overview request to the stock library to place a unique piece */
    public const string STOCK_PLACE_UNIQUE = 'stock_place_unique';

    /** @var string Stock library answer to the Overview placement request */
    public const string ADMIN_PRODUCT_WAREHOUSE_PLACE_DONE = 'admin_product_warehouse_place_done';

    /** @var string Product picture publish action name */
    public const string PRODUCT_PICTURE_PUBLISH = 'product_picture_publish';

    /** @var string Files door → products library: the answer to a product picture publication */
    public const string PRODUCT_PICTURE_PUBLISHED = 'product_picture_published';

    /** @var string Subscription page admin product signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_PRODUCT = 'subscription_page_admin_product';

    /** @var string Browser subscription marker for the product's Stock page */
    public const string SUBSCRIPTION_PAGE_ADMIN_PRODUCT_STOCK = 'subscription_page_admin_product_stock';

    /** @var string Browser action to set a product's units in one warehouse */
    public const string PRODUCT_STOCK_SET_UNITS = 'product_stock_set_units';

    /** @var string Stock page → stock library: set units in one warehouse */
    public const string STOCK_SET_UNITS = 'stock_set_units';

    /** @var string Stock library → Stock page: the outcome of setting units */
    public const string ADMIN_PRODUCT_STOCK_SET_UNITS_DONE = 'admin_product_stock_set_units_done';

    /** @var string Storefront action to remove one saved cart line */
    public const string CART_REMOVE_LINE = 'cart_remove_line';

    /** @var string Browser action to step the quantity of a saved cart line */
    public const string CART_STEP_QUANTITY = 'cart_step_quantity';

    /** @var string Server → client, WS_GROUP: the whole cart of one guest session or one person, sent by its owner */
    public const string CART_STATE = 'cart_state';

    /** @var string Browser action to add a product from its storefront card */
    public const string PRODUCT_ADD_TO_CART = 'product_add_to_cart';

    /** @var string Browser action to add a product from its category */
    public const string CATEGORY_ADD_TO_CART = 'category_add_to_cart';

    /** @var string Storefront page → guest carts library: add a product to the session's cart */
    public const string GUEST_CART_ADD_PRODUCT = 'guest_cart_add_product';

    /** @var string Storefront page → person's agent: add a product to the person's cart */
    public const string ACCOUNT_CART_ADD_PRODUCT = 'account_cart_add_product';

    /** @var string Cart owner → original product agent: the outcome of adding a product */
    public const string CART_PRODUCT_ADD_DONE = 'cart_product_add_done';

    /** @var string Cart owner → Category page on the categories library: the outcome of adding a product */
    public const string CART_CATEGORY_ADD_DONE = 'cart_category_add_done';

    /** @var string Shop connections agent → guest carts library: tell every tab of a session its cart */
    public const string GUEST_CART_SEND = 'guest_cart_send';

    /** @var string Shop connections agent → the person's agent: tell every tab and device of the person the cart */
    public const string ACCOUNT_CART_SEND = 'account_cart_send';
}
