<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** An exact guest cart quantity requested over the test stand's command socket. */
final class GuestCartSetQuantityCommandData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Existing guest session's cookie token
     * @param int $productId Product to put in or take out of the cart
     * @param int $quantity Exact quantity to store, zero for removal
     * @throws InvalidFormatException When any field is outside its accepted range
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly int $productId,
        public readonly int $quantity,
    ) {
        if ($sessionToken === '' || $productId <= 0 || $quantity < 0) {
            throw new InvalidFormatException('A guest cart fixture needs a session token, a product ID and a nonnegative quantity');
        }
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Validated request
     * @throws InvalidFormatException When a required field is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, EcommerceShopCommandConstants::sessionToken),
            self::requireInt($data, EcommerceShopCommandConstants::productId),
            self::requireInt($data, EcommerceShopCommandConstants::quantity),
        );
    }

    /** @return array{sessionToken: string, productId: int, quantity: int} Command payload */
    public function toArray(): array
    {
        return [
            EcommerceShopCommandConstants::sessionToken => $this->sessionToken,
            EcommerceShopCommandConstants::productId => $this->productId,
            EcommerceShopCommandConstants::quantity => $this->quantity,
        ];
    }
}
