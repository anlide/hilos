<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Actions\CartVoucherRules;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\Voucher as EntityVoucher;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Money\Money;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Files\HilosFiles;

/**
 * The storefront cart's complete data, with all monetary amounts in kopecks.
 *
 * @phpstan-type CartLine array{
 *     lineId: int, productId: int, slug: string, name: string, kind: string, pictureUrl: string,
 *     price: int, quantity: int, availableQuantity: int, lineTotal: string
 * }
 * @phpstan-type CartVoucher array{id: int, code: string, active: bool, discount: string}
 */
final readonly class CartPageData
{
    public const string lineId = 'lineId';

    private const string productId = 'productId';
    private const string pictureUrl = 'pictureUrl';
    private const string quantity = 'quantity';
    private const string availableQuantity = 'availableQuantity';
    private const string lineTotal = 'lineTotal';
    private const string userId = 'userId';
    private const string guestCartKey = 'guestCartKey';
    private const string lines = 'lines';
    private const string itemsTotal = 'itemsTotal';
    private const string voucher = 'voucher';
    private const string discount = 'discount';
    private const string total = 'total';

    /**
     * @param ?int $userId The owning account, or null for a guest
     * @param ?string $guestCartKey The opaque guest cart identity, or null for an account
     * @param list<CartLine> $lines Cart lines in insertion order
     * @param ?Voucher $voucher The selected catalog voucher, including an inactive one, or null when none is selected
     */
    public function __construct(
        private ?int $userId,
        private ?string $guestCartKey,
        private array $lines,
        private ?Voucher $voucher,
    ) {
    }

    /**
     * @param int $lineId Primary key of the saved cart line
     * @param Product $product The catalog item referenced by the saved line
     * @param int $quantity The saved selection
     * @param int $availableQuantity The total stock across warehouses
     * @return CartLine The line as rendered at the current price and stock
     * @throws InvalidArgumentException When the catalog picture variant is misconfigured
     * @throws LogicException When the price or counted quantity is below zero
     */
    public static function line(int $lineId, Product $product, int $quantity, int $availableQuantity): array
    {
        $kind = $product->kind;
        $price = $product->price;
        $quantity = $kind === ProductKind::UNIQUE ? CartLineRules::QUANTITY_MIN : $quantity;

        return [
            self::lineId => $lineId,
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
     * The items are summed once; an inactive voucher takes nothing off, so the total is then the items.
     *
     * @return array{userId: ?int, guestCartKey: ?string, lines: list<CartLine>, itemsTotal: string, voucher: ?CartVoucher, total: string}
     *     Page data sent to the browser
     * @throws LogicException When a line total is not a canonical decimal string or the discount is outside the items
     */
    public function toArray(): array
    {
        $items = Money::sumExact(...array_column($this->lines, self::lineTotal));
        $discount = Money::discountOfExact($items, CartVoucherRules::effectiveDiscountPercent($this->voucher));

        return [
            self::userId => $this->userId,
            self::guestCartKey => $this->guestCartKey,
            self::lines => $this->lines,
            self::itemsTotal => $items,
            self::voucher => $this->voucher === null ? null : [
                EntityVoucher::id => $this->voucher->id,
                EntityVoucher::code => $this->voucher->code,
                EntityVoucher::active => $this->voucher->active,
                self::discount => $discount,
            ],
            self::total => Money::totalAfterDiscountExact($items, $discount),
        ];
    }
}
