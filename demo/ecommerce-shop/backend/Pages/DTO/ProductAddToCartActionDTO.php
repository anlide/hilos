<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Adds one product from the accepted product card to the acting connection's cart. */
final class ProductAddToCartActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param int $productId Primary key from the product card
     * @throws InvalidFormatException When the product ID is not positive
     */
    public function __construct(public readonly int $productId)
    {
        if ($productId <= 0) {
            throw new InvalidFormatException('Product ID must be a positive integer.');
        }
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Product named by the click
     * @throws InvalidFormatException When the payload lacks a positive integer product ID
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The add-to-cart action needs an object payload.');
        }

        return new static(self::requireInt($inner, CartStateSignalData::PRODUCT_ID));
    }

    /** @return string Add-to-cart action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART;
    }

    /** @return array{productId: int} Product named by the click */
    public function toArray(): array
    {
        return [CartStateSignalData::PRODUCT_ID => $this->productId];
    }
}
