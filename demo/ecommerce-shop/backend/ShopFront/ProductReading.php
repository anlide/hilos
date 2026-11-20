<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\ShopFront;

use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Database\View\Item\Product;

/**
 * What the agent of one product page address reads: the product the address names and its stock.
 *
 * The product's current address is `$product->slug`. When it differs from the address of the agent
 * that read it, that address is one the product had before a rename ({@see ProductAgent::reading()}).
 */
final readonly class ProductReading
{
    /**
     * @param Product $product The product the address names, now or before a rename
     * @param int $units Its units summed over every warehouse that keeps it, zero when none does
     */
    public function __construct(
        public Product $product,
        public int $units,
    ) {
    }
}
