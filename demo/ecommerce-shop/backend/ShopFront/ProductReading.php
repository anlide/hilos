<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\ShopFront;

use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductSlug as EntityProductSlug;
use Demo\EcommerceShop\Database\View\Item\Category;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseNames;
use Hilos\HilosException;
use Throwable;

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

    /**
     * Current addresses take precedence over history. Address and placement are read
     * in one PRIMARY snapshot before any page or cart count is built.
     *
     * @param string $slug Exact storefront address
     * @return ?self Catalog snapshot, or null when no product has that address
     * @throws HilosException When the collections or the transaction refuse the read
     * @throws LogicException When the catalog read fails unexpectedly
     */
    public static function atAddress(string $slug): ?self
    {
        if (!ProductPageAddresses::isUsableSlug($slug)) {
            return null;
        }

        try {
            return Database::readSnapshot(DatabaseNames::PRIMARY, static function () use ($slug): ?self {
                foreach (Hilos::$db->products->storedProducts(EntityProduct::slug, $slug) as $product) {
                    if ($product->slug === $slug) {
                        return self::forProduct($product);
                    }
                }

                $productId = Hilos::$db->productSlugs->storedAddresses(EntityProductSlug::slug, $slug)[$slug] ?? null;
                if ($productId === null) {
                    return null;
                }

                $product = Hilos::$db->products->storedProducts(EntityProduct::id, $productId)->current();
                return $product === null ? null : self::forProduct($product);
            });
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('The product address could not be read', previous: $failure);
        }
    }

    /**
     * @param Product $product Stored product read in the current snapshot
     * @return self Product, category and persisted availability
     * @throws HilosException When a catalog collection refuses the read
     */
    private static function forProduct(Product $product): self
    {
        return new self(
            $product,
            Hilos::$db->categories[$product->categoryId],
            Hilos::$db->productWarehouseStocks->storedUnitsOfProduct($product->id),
        );
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
