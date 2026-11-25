<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\ShopFront;

use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Entity\Item\Product;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseNames;
use Hilos\HilosException;
use Throwable;

/**
 * Reads and presents the number of stored carts containing one product.
 */
final class ProductCartCount
{
    private const string productId = 'productId';
    private const string cartCount = 'cartCount';

    /**
     * Counts both kinds of cart in one primary snapshot, including during a guest-to-account transfer.
     *
     * @param int $productId Product primary id
     * @return int Number of saved cart lines for the product
     * @throws HilosException When the collections or transaction refuse the read
     * @throws LogicException When the read fails unexpectedly
     */
    public static function forProduct(int $productId): int
    {
        try {
            return Database::readSnapshot(
                DatabaseNames::PRIMARY,
                static fn (): int => Hilos::$db->sessionProductCarts->countForProduct($productId)
                    + Hilos::$db->userProductCarts->countForProduct($productId),
            );
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('The product cart count could not be read', previous: $failure);
        }
    }

    /**
     * @param int $productId Product primary id
     * @param int $cartCount Number of carts containing the product
     * @param ?string $slug Current storefront address, when included by the page
     * @return array{productId: int, slug?: string, cartCount: int} Public aggregate fields
     */
    public static function data(int $productId, int $cartCount, ?string $slug = null): array
    {
        $data = [self::productId => $productId];
        if ($slug !== null) {
            $data[Product::slug] = $slug;
        }
        $data[self::cartCount] = $cartCount;

        return $data;
    }
}
