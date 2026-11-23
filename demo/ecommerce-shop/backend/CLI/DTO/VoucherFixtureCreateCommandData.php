<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Creates one voucher fixture with its initial discount and active flag. */
final class VoucherFixtureCreateCommandData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $code Fixture code
     * @param int $discountPercent Discount as a whole percentage
     * @param bool $active Initial active flag
     */
    public function __construct(
        public readonly string $code,
        public readonly int $discountPercent,
        public readonly bool $active,
    ) {
    }

    /** @return array{code: string, discountPercent: int, active: bool} Command payload */
    public function toArray(): array
    {
        return [
            EcommerceShopCommandConstants::code => $this->code,
            EcommerceShopCommandConstants::discountPercent => $this->discountPercent,
            EcommerceShopCommandConstants::active => $this->active,
        ];
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Parsed fixture request
     * @throws InvalidFormatException When the payload lacks its required fields or carries invalid types
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, EcommerceShopCommandConstants::code),
            self::requireInt($data, EcommerceShopCommandConstants::discountPercent),
            self::requireBool($data, EcommerceShopCommandConstants::active),
        );
    }
}
