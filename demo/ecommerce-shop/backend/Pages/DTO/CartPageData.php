<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Money\Money;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Files\HilosFiles;

/**
 * The storefront cart's complete data, with all monetary amounts in kopecks.
 *
 * @phpstan-type CartLine array{
 *     productId: int, slug: string, name: string, kind: string, pictureUrl: string,
 *     price: int, quantity: int, availableQuantity: int, lineTotal: string
 * }
 */
final readonly class CartPageData
{
    private const string productId = 'productId';
    private const string pictureUrl = 'pictureUrl';
    private const string quantity = 'quantity';
    private const string availableQuantity = 'availableQuantity';
    private const string lineTotal = 'lineTotal';
    private const string userId = 'userId';
    private const string lines = 'lines';
    private const string itemsTotal = 'itemsTotal';

    /**
     * @param ?int $userId The owning account, or null for a guest
     * @param list<CartLine> $lines Cart lines in insertion order
     */
    public function __construct(private ?int $userId, private array $lines)
    {
    }

    /**
     * @param Product $product The catalog item referenced by the saved line
     * @param int $quantity The saved selection
     * @param int $availableQuantity The total stock across warehouses
     * @return CartLine The line as rendered at the current price and stock
     * @throws InvalidArgumentException When the catalog picture variant is misconfigured
     * @throws LogicException When the price or counted quantity is below zero
     */
    public static function line(Product $product, int $quantity, int $availableQuantity): array
    {
        $kind = $product->kind;
        $price = $product->price;
        $quantity = $kind === ProductKind::UNIQUE ? CartLineRules::QUANTITY_MIN : $quantity;

        return [
            self::productId => $product->id,
            EntityProduct::slug => $product->slug,
            EntityProduct::name => $product->name,
            EntityProduct::kind => $kind->value,
            self::pictureUrl => HilosFiles::downloadPath($product->pictureFileId, CatalogPictureUploadTarget::VARIANT_SM),
            EntityProduct::price => $price,
            self::quantity => $quantity,
            self::availableQuantity => $availableQuantity,
            self::lineTotal => Money::lineTotalExact($price, min($quantity, $availableQuantity)),
        ];
    }

    /**
     * @return array{userId: ?int, lines: list<CartLine>, itemsTotal: string} Page data sent to the browser
     * @throws LogicException When a line total is not a canonical decimal string
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::lines => $this->lines,
            self::itemsTotal => Money::sumExact(...array_column($this->lines, self::lineTotal)),
        ];
    }
}
