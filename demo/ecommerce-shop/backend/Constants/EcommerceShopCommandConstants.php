<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Constants;

/**
 * EcommerceShopCommandConstants - what the shop names on the command channel.
 *
 * Names the project's test-only commands and the fields of their payloads and replies.
 */
final class EcommerceShopCommandConstants
{
    public const string WAREHOUSE_TEST_DELETE = 'test:ecommerce-shop:warehouse-delete';
    public const string CATEGORY_TEST_DELETE = 'test:ecommerce-shop:category-delete';
    public const string PRODUCT_TEST_DELETE = 'test:ecommerce-shop:product-delete';
    public const string VOUCHER_FIXTURE_CREATE = 'test:ecommerce-shop:voucher-fixture-create';
    public const string VOUCHER_FIXTURE_GET = 'test:ecommerce-shop:voucher-fixture-get';
    public const string VOUCHER_FIXTURE_UPDATE = 'test:ecommerce-shop:voucher-fixture-update';
    public const string VOUCHER_FIXTURE_SET_ACTIVE = 'test:ecommerce-shop:voucher-fixture-set-active';
    public const string VOUCHER_FIXTURE_DELETE = 'test:ecommerce-shop:voucher-fixture-delete';
    public const string name = 'name';
    public const string deleted = 'deleted';
    public const string voucher = 'voucher';
    public const string id = 'id';
    public const string code = 'code';
    public const string newCode = 'newCode';
    public const string discountPercent = 'discountPercent';
    public const string active = 'active';
}
