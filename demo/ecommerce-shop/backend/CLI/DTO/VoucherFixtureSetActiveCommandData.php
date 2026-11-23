<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Sets the active flag on one voucher fixture. */
final class VoucherFixtureSetActiveCommandData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $code Fixture code
     * @param bool $active Desired active flag
     */
    public function __construct(
        public readonly string $code,
        public readonly bool $active,
    ) {
    }

    /** @return array{code: string, active: bool} Command payload */
    public function toArray(): array
    {
        return [
            EcommerceShopCommandConstants::code => $this->code,
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
            self::requireBool($data, EcommerceShopCommandConstants::active),
        );
    }
}
