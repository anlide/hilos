<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Identifies one voucher fixture by code. */
final class VoucherFixtureCodeCommandData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $code Fixture code
     */
    public function __construct(public readonly string $code)
    {
    }

    /** @return array{code: string} Command payload */
    public function toArray(): array
    {
        return [
            EcommerceShopCommandConstants::code => $this->code,
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
        );
    }
}
