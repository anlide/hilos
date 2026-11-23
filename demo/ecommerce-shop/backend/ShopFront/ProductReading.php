<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\ShopFront;

use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Database\View\Item\Category;
use Demo\EcommerceShop\Database\View\Item\Product;

/**
 * The product an address names, its category and its availability across warehouses.
 *
 * The product's current address is `$product->slug`. When it differs from the address of the agent
 * that read it, that address is one the product had before a rename ({@see ProductAgent::reading()}).
 */
final readonly class ProductReading
{
    /** @var ProductAvailability Availability at the time of the reading */
    public ProductAvailability $availability;

    /**
     * @param Product $product The product the address names, now or before a rename
     * @param Category $category The product's category
     * @param int $units Its units summed over every warehouse that keeps it, zero when none does
     */
    public function __construct(
        public Product $product,
        public Category $category,
        public int $units,
    ) {
        $this->availability = ProductAvailability::of($product->kind, $units);
    }

    /** @return string Compact comparison of the source values that draw the public card */
    public function fingerprint(): string
    {
        return hash('sha256', serialize([
            $this->product->id,
            $this->product->slug,
            $this->product->name,
            $this->product->kind->value,
            $this->product->price,
            $this->product->description,
            $this->product->pictureFileId,
            $this->units,
            $this->category->name,
            $this->category->slug,
        ]));
    }
}
