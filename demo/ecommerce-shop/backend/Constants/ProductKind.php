<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Constants;

/**
 * ProductKind - the kind of a product of the shop's catalog.
 *
 * The values of the kind column. A product keeps the kind it was created with.
 */
enum ProductKind: string
{
    /** Unique piece: one of a kind, no quantity */
    case UNIQUE = 'unique';

    /** Stock item: units in warehouses */
    case STOCK = 'stock';
}
