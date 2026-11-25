<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** An exact quantity edit addressed to the saved line shown by the cart page. */
final class CartSetQuantityActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    private const string ownerKind = 'ownerKind';
    private const string quantity = 'quantity';
    private const string expectedQuantity = 'expectedQuantity';

    /**
     * @param int $lineId Primary key of the saved line
     * @param string $ownerKind Cart kind shown when the line was rendered
     * @param string $quantity Original quantity text from the form
     * @param int $expectedQuantity Saved quantity the edit started from
     * @throws InvalidFormatException When the target or expected quantity is outside the action contract
     */
    public function __construct(
        public readonly int $lineId,
        public readonly string $ownerKind,
        public readonly string $quantity,
        public readonly int $expectedQuantity,
    ) {
        if ($lineId <= 0) {
            throw new InvalidFormatException('Cart line ID must be positive');
        }
        if ($ownerKind !== CartStepQuantityActionDTO::GUEST && $ownerKind !== CartStepQuantityActionDTO::ACCOUNT) {
            throw new InvalidFormatException('Unknown cart owner kind');
        }
        if ($expectedQuantity < CartLineRules::QUANTITY_MIN || $expectedQuantity > CartLineRules::QUANTITY_MAX) {
            throw new InvalidFormatException('Review the current cart quantity before changing it.');
        }
    }

    /** @return string Action name on the cart page */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::CART_SET_QUANTITY;
    }

    /**
     * @param array<string, mixed> $data Flat action payload
     * @return static Parsed action payload with the original quantity text
     * @throws InvalidFormatException When the payload shape, target or expected quantity is invalid
     */
    public static function fromArray(array $data): static
    {
        if (array_diff_key($data, [
            CartPageData::lineId => true,
            self::ownerKind => true,
            self::quantity => true,
            self::expectedQuantity => true,
        ]) !== []) {
            throw new InvalidFormatException('Cart quantity action carries an unknown field');
        }

        return new static(
            lineId: self::requireInt($data, CartPageData::lineId),
            ownerKind: self::requireString($data, self::ownerKind),
            quantity: self::requireString($data, self::quantity),
            expectedQuantity: self::requireInt($data, self::expectedQuantity),
        );
    }

    /** @return array{lineId: int, ownerKind: string, quantity: string, expectedQuantity: int} Action payload fields */
    public function toArray(): array
    {
        return [
            CartPageData::lineId => $this->lineId,
            self::ownerKind => $this->ownerKind,
            self::quantity => $this->quantity,
            self::expectedQuantity => $this->expectedQuantity,
        ];
    }
}
