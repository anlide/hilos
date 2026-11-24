<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** A one-unit step addressed to the saved line shown by the cart page. */
final class CartStepQuantityActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    public const string GUEST = 'guest';
    public const string ACCOUNT = 'account';

    private const string ownerKind = 'ownerKind';
    private const string step = 'step';

    /**
     * @param int $lineId Primary key of the saved line
     * @param string $ownerKind Cart kind shown when the line was rendered
     * @param int $step One unit up or down
     * @throws InvalidFormatException When the target or direction is outside the action contract
     */
    public function __construct(
        public readonly int $lineId,
        public readonly string $ownerKind,
        public readonly int $step,
    ) {
        if ($lineId <= 0) {
            throw new InvalidFormatException('Cart line ID must be positive');
        }
        if ($ownerKind !== self::GUEST && $ownerKind !== self::ACCOUNT) {
            throw new InvalidFormatException('Unknown cart owner kind');
        }
        if ($step !== CartLineRules::STEP_DOWN && $step !== CartLineRules::STEP_UP) {
            throw new InvalidFormatException('Cart quantity must step by one unit');
        }
    }

    /** @return string Action name on the cart page */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::CART_STEP_QUANTITY;
    }

    /**
     * @param array<string, mixed> $data Flat action payload
     * @return static Parsed action payload
     * @throws InvalidFormatException When the shape, target or direction is invalid
     */
    public static function fromArray(array $data): static
    {
        if (array_diff_key($data, [CartPageData::lineId => true, self::ownerKind => true, self::step => true]) !== []) {
            throw new InvalidFormatException('Cart quantity action carries an unknown field');
        }

        return new static(
            lineId: self::requireInt($data, CartPageData::lineId),
            ownerKind: self::requireString($data, self::ownerKind),
            step: self::requireInt($data, self::step),
        );
    }

    /** @return array{lineId: int, ownerKind: string, step: int} Action payload fields */
    public function toArray(): array
    {
        return [
            CartPageData::lineId => $this->lineId,
            self::ownerKind => $this->ownerKind,
            self::step => $this->step,
        ];
    }
}
