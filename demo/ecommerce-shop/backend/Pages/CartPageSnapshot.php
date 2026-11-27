<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CartPageData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * Assembles cart data for a page that has confirmed the connection's cart owner.
 *
 * @phpstan-import-type CartLine from CartPageData
 */
final class CartPageSnapshot
{
    /**
     * @param UserAgent $owner Confirmed account cart owner
     * @param int $userId ID of that account
     * @return CartPageData Account cart at the current price, stock and voucher terms
     * @throws InvalidArgumentException When a saved row, stock total or picture variant is invalid
     * @throws LogicException When a collection is misconfigured or a monetary amount is invalid
     * @throws DatabaseException When cart lines, products, stock or voucher cannot be read
     * @throws ItemNotFoundException When the catalog or saved voucher cannot provide its keyed item
     * @throws ObjectGetIdStringNotImplementedException When a stock row cannot provide a key
     * @throws CollectionNotManualException When a stock selection refuses a matched row
     * @throws HilosException When a configured data source refuses a read
     */
    public static function forAccount(UserAgent $owner, int $userId): CartPageData
    {
        $lines = self::lines(Hilos::$db->userProductCarts->storedCartLines($userId));

        return new CartPageData($userId, null, $lines, $owner->cartVoucher());
    }

    /**
     * @param GuestCartsLibraryAgent $owner Confirmed guest cart owner
     * @param int $sessionId Session holding the guest cart
     * @param int $cartEpoch Active generation of the guest cart
     * @param string $guestCartKey Opaque identity of that cart
     * @return CartPageData Guest cart at the current price, stock and voucher terms
     * @throws InvalidArgumentException When a saved row, stock total or picture variant is invalid
     * @throws LogicException When a collection is misconfigured or a monetary amount is invalid
     * @throws DatabaseException When cart lines, products, stock or voucher cannot be read
     * @throws ItemNotFoundException When the catalog or saved voucher cannot provide its keyed item
     * @throws ObjectGetIdStringNotImplementedException When a stock row cannot provide a key
     * @throws CollectionNotManualException When a stock selection refuses a matched row
     * @throws HilosException When a configured data source refuses a read
     */
    public static function forGuest(GuestCartsLibraryAgent $owner, int $sessionId, int $cartEpoch, string $guestCartKey): CartPageData
    {
        $lines = self::lines(Hilos::$db->sessionProductCarts->storedCartLines($sessionId, $cartEpoch));

        return new CartPageData(null, $guestCartKey, $lines, $owner->cartVoucher($sessionId, $cartEpoch));
    }

    /**
     * @param iterable<int, array{int, int}> $cartLines Saved product ID and quantity by line ID
     * @return list<CartLine> Cart lines joined to current prices and stock in saved order
     * @throws InvalidArgumentException When a loaded item, stock total or picture variant is invalid
     * @throws LogicException When a collection is misconfigured or a monetary amount is invalid
     * @throws DatabaseException When products or stock cannot be read
     * @throws ItemNotFoundException When a keyed product read fails
     * @throws ObjectGetIdStringNotImplementedException When a stock row cannot provide a key
     * @throws CollectionNotManualException When a stock selection refuses a matched row
     * @throws HilosException When a configured data source refuses a read
     */
    private static function lines(iterable $cartLines): array
    {
        $ordered = [];
        $productIds = [];
        foreach ($cartLines as $lineId => [$productId, $quantity]) {
            $ordered[$lineId] = [$productId, $quantity];
            $productIds[] = $productId;
        }

        $stock = Hilos::$db->productWarehouseStocks->totalsOfProducts(...$productIds);
        $lines = [];
        foreach ($ordered as $lineId => [$productId, $quantity]) {
            $product = Hilos::$db->products[$productId] ?? null;
            if ($product === null) {
                // A product can disappear with its cart line while the catalog change is crossing workers.
                continue;
            }

            $lines[] = CartPageData::line($lineId, $product, $quantity, $stock->of($productId)->unitCount);
        }

        return $lines;
    }
}
