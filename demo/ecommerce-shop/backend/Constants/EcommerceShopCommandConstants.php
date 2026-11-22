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
    public const string name = 'name';
    public const string deleted = 'deleted';
}
