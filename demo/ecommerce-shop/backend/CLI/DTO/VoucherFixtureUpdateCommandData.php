<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Changes the code, discount, or both for one voucher fixture. */
final class VoucherFixtureUpdateCommandData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $code Current fixture code
     * @param ?string $newCode Replacement fixture code, or null to keep it
     * @param ?int $discountPercent Replacement discount, or null to keep it
     * @throws InvalidFormatException When neither field is supplied
     */
    public function __construct(
        public readonly string $code,
        public readonly ?string $newCode,
        public readonly ?int $discountPercent,
    ) {
        if ($newCode === null && $discountPercent === null) {
            throw new InvalidFormatException('Specify a new voucher code or discount');
        }
    }

    /** @return array{code: string, newCode: string|null, discountPercent: int|null} Command payload */
    public function toArray(): array
    {
        return [
            EcommerceShopCommandConstants::code => $this->code,
            EcommerceShopCommandConstants::newCode => $this->newCode,
            EcommerceShopCommandConstants::discountPercent => $this->discountPercent,
        ];
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Parsed fixture request
     * @throws InvalidFormatException When the payload lacks its required fields or carries invalid types, or requests no change
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, EcommerceShopCommandConstants::code),
            self::optionalString($data, EcommerceShopCommandConstants::newCode),
            self::optionalInt($data, EcommerceShopCommandConstants::discountPercent),
        );
    }
}
