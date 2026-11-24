<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Sets or clears a guest cart's fixture voucher over the test stand's command socket. */
final class GuestCartSetVoucherCommandData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Existing guest session's cookie token
     * @param string $code Voucher fixture code
     * @param bool $applied Whether the guest cart should hold the fixture voucher
     * @throws InvalidFormatException When the session token is empty
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly string $code,
        public readonly bool $applied,
    ) {
        if ($sessionToken === '') {
            throw new InvalidFormatException('A guest cart voucher fixture needs a session token');
        }
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Parsed fixture request
     * @throws InvalidFormatException When a required field is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, EcommerceShopCommandConstants::sessionToken),
            self::requireString($data, EcommerceShopCommandConstants::code),
            self::requireBool($data, EcommerceShopCommandConstants::applied),
        );
    }

    /** @return array{sessionToken: string, code: string, applied: bool} Command payload */
    public function toArray(): array
    {
        return [
            EcommerceShopCommandConstants::sessionToken => $this->sessionToken,
            EcommerceShopCommandConstants::code => $this->code,
            EcommerceShopCommandConstants::applied => $this->applied,
        ];
    }
}
